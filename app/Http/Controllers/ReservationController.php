<?php

namespace App\Http\Controllers;

use App\Http\Resources\ReservationResource;
use App\Models\Car;
use App\Models\CarMaintenancePeriod;
use App\Models\Payment;
use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReservationController extends Controller
{
    public function store(Request $request)
    {
        // Require a Sanctum-authenticated user even if this method is accidentally routed elsewhere.
        if (!Auth::guard('sanctum')->check()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $user = auth()->user();

        // Only client accounts can create reservations.
        if ($user->role !== 'client') {
            return response()->json([
                'message' => 'Only clients can create reservations',
            ], 403);
        }

        // Block users whose account status is not active.
        if ($user->status !== 'active') {
            return response()->json([
                'message' => 'Your account is not allowed to create reservations',
            ], 403);
        }

        // Block users who are temporarily restricted by the cancellation limiter.
        if ($user->blocked_until && now()->lessThan($user->blocked_until)) {
            return response()->json([
                'message' => 'You are temporarily blocked from creating reservations',
            ], 403);
        }

        $data = $request->validate([
            'car_id'     => 'required|exists:cars,id',
            // Require ISO-style datetime so the exact pickup hour is captured.
            // after_or_equal:now prevents booking a time already in the past.
            'start_date' => 'required|date_format:Y-m-d H:i|after_or_equal:now',
            'end_date'   => 'required|date_format:Y-m-d H:i|after:start_date',
        ]);

        // Keep the availability check and insert in one transaction to reduce double-booking risk.
        return DB::transaction(function () use ($data) {
            // Lock the selected car row while this reservation is being checked and created.
            $car = Car::whereKey($data['car_id'])->lockForUpdate()->firstOrFail();

            // Only approved agencies should receive new reservations.
            if ($car->agency?->status !== 'approved') {
                return response()->json([
                    'message' => 'This agency is not accepting reservations',
                ], 400);
            }

            // Calculate billable 24-hour blocks from the validated datetime range.
            // Using hours then ceiling so 17:00→17:00 next day = 24h = 1 block,
            // and 17:00→10:00 next day = 17h = ceil(17/24) = 1 block (minimum 1).
            $start = Carbon::parse($data['start_date']);
            $end   = Carbon::parse($data['end_date']);
            $days  = max(1, (int) ceil($start->diffInHours($end) / 24));
            $total = $car->price_per_day * $days;

            // Expire stale pending reservations before checking whether this car is free.
            Reservation::where('car_id', $data['car_id'])
                ->where('status', 'pending')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancelled_by' => null,
                    'cancellation_reason' => 'Reservation expired before agency confirmation.',
                ]);

            // Check active reservation windows instead of the car status field.
            $overlap = Reservation::where('car_id', $data['car_id'])
                ->whereIn('status', ['pending', 'confirmed'])
                ->where(function ($query) use ($data) {
                    $query->where('start_date', '<', $data['end_date'])
                        ->where('end_date', '>', $data['start_date']);
                })
                ->exists();

            if ($overlap) {
                return response()->json([
                    'message' => 'Car is already reserved for these dates',
                ], 400);
            }

            // Check scheduled maintenance windows for the requested reservation dates.
            $maintenanceOverlap = CarMaintenancePeriod::where('car_id', $data['car_id'])
                ->where('status', 'scheduled')
                ->where(function ($query) use ($data) {
                    $query->where('start_date', '<', $data['end_date'])
                        ->where('end_date', '>', $data['start_date']);
                })
                ->exists();

            if ($maintenanceOverlap) {
                return response()->json([
                    'message' => 'Car is scheduled for maintenance during these dates',
                ], 400);
            }

            // Create the reservation using the current car price as an immutable price snapshot.
            $reservation = Reservation::create([
                'id' => Str::uuid(),
                'client_id' => auth()->id(),
                'car_id' => $car->id,
                'agency_id' => $car->agency_id,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'price_per_day_snapshot' => $car->price_per_day,
                'total_amount' => $total,
                'status' => 'pending',
                // Pending reservations expire after one hour if the agency does not confirm them.
                'expires_at' => now()->addHour(),
            ]);

            return response()->json([
                'message' => 'Reservation created',
                'data' => $reservation,
            ]);
        });
    }

    public function index(Request $request)
    {
        $user = auth()->user();

        // Optional ?status= filter — e.g. ?status=pending shows only pending reservations.
        $validStatuses = ['pending', 'confirmed', 'cancelled', 'completed'];
        $statusFilter  = in_array($request->status, $validStatuses, true) ? $request->status : null;

        // Admins can list all reservations for platform oversight.
        if ($user->role === 'admin') {
            $query = Reservation::with(['car.images', 'agency.city']);
        }
        // Clients can list only their own reservations.
        elseif ($user->role === 'client') {
            $query = Reservation::with(['car.images', 'agency.city'])
                ->where('client_id', $user->id);
        }
        // Agency owners can list reservations only after an agency profile exists.
        elseif ($user->role === 'agency_owner' && $user->agency) {
            $query = Reservation::with(['car.images', 'agency.city'])
                ->where('agency_id', $user->agency->id);
        } else {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($statusFilter) {
            $query->where('status', $statusFilter);
        }

        $reservations = $query->latest()->paginate(20);

        // Return the shared resource so index and show expose the same shape.
        return ReservationResource::collection($reservations);
    }

    public function show($id)
    {
        $reservation = Reservation::with([
            'car.images',
            'agency.city',
        ])->findOrFail($id);

        // Enforce reservation visibility rules from the policy.
        $this->authorize('view', $reservation);

        // Return the shared resource so agency details stay hidden until confirmation/completion.
        return new ReservationResource($reservation);
    }

    public function confirm($id)
    {
        // Keep balance deduction, payment creation, and status update atomic.
        return DB::transaction(function () use ($id) {
            $reservation = Reservation::whereKey($id)->lockForUpdate()->firstOrFail();

            // Enforce agency ownership before charging the agency balance.
            $this->authorize('confirm', $reservation);

            // Only pending reservations can be confirmed.
            if ($reservation->status !== 'pending') {
                return response()->json([
                    'message' => 'Only pending reservations can be confirmed',
                ], 400);
            }

            // Auto-cancel expired pending reservations before any balance is charged.
            if ($reservation->expires_at && now()->greaterThanOrEqualTo($reservation->expires_at)) {
                $reservation->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    // System expiry is represented by a reason because cancelled_by only stores client/agency.
                    'cancelled_by' => null,
                    'cancellation_reason' => 'Reservation expired before agency confirmation.',
                ]);

                return response()->json([
                    'message' => 'Reservation expired and was cancelled automatically',
                ], 400);
            }

            // Lock the agency row so balance math stays consistent under concurrent requests.
            $agency = $reservation->agency()->lockForUpdate()->firstOrFail();

            // Calculate the platform commission from the reservation total.
            $commission = $reservation->total_amount * 0.1;

            if ($agency->balance < $commission) {
                return response()->json([
                    'message' => 'Insufficient balance',
                ], 400);
            }

            // Store balance snapshots for an auditable payment ledger.
            $before = $agency->balance;
            $after = $before - $commission;

            $agency->update([
                'balance' => $after,
            ]);

            // Record the commission payment once the agency balance is deducted.
            Payment::create([
                'id' => Str::uuid(),
                'reservation_id' => $reservation->id,
                'agency_id' => $agency->id,
                'amount' => $commission,
                'type' => 'commission',
                'status' => 'completed',
                'balance_before' => $before,
                'balance_after' => $after,
                'reference' => 'COM-' . $reservation->id,
            ]);

            // Mark the reservation as confirmed after the commission has been recorded.
            $reservation->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
                // Clear expiry after confirmation because the reservation is no longer pending.
                'expires_at' => null,
            ]);

            return response()->json([
                'message' => 'Reservation confirmed successfully',
            ]);
        });
    }

    public function cancel($id)
    {
        // Keep cancellation, refund, and client blocking rules in one transaction.
        return DB::transaction(function () use ($id) {
            $reservation = Reservation::with(['agency', 'client'])->lockForUpdate()->findOrFail($id);

            // Enforce that only the reservation client or owning agency can cancel through this endpoint.
            $this->authorize('cancel', $reservation);

            $user = auth()->user();
            $client = $reservation->client;

            // Prevent repeating the same cancellation.
            if ($reservation->status === 'cancelled') {
                return response()->json([
                    'message' => 'Reservation already cancelled',
                ], 400);
            }

            // Completed reservations are final and should not be cancelled.
            if ($reservation->status === 'completed') {
                return response()->json([
                    'message' => 'Cannot cancel completed reservation',
                ], 400);
            }

            // Once the client has physically picked up the car, the rental is underway
            // and cannot be cancelled through the app — the agency must handle it directly.
            if ($reservation->picked_up_at) {
                return response()->json([
                    'message' => 'Cannot cancel a reservation after the car has been picked up',
                ], 400);
            }

            // ---- CLIENT cancellation throttle ----
            // Blocked clients cannot keep cancelling reservations during the block window.
            if ((string) $user->id === (string) $client->id && $client->blocked_until && now()->lessThan($client->blocked_until)) {
                return response()->json([
                    'message' => 'You are temporarily blocked from cancelling reservations',
                ], 403);
            }

            // Increment the client's daily cancel counter before checking the limit.
            if ((string) $user->id === (string) $client->id) {
                $client->increment('cancel_count_today');
                $client->refresh();
            }

            // Block the client after 2 cancellations in a single day (3rd attempt is rejected).
            if ((string) $user->id === (string) $client->id && $client->cancel_count_today > 2) {
                $client->update([
                    'blocked_until'      => now()->addHours(24),
                    'cancel_count_today' => 0,
                ]);

                return response()->json([
                    'message' => 'Too many cancellations today. You are blocked for 24 hours.',
                ], 403);
            }

            $agency = $reservation->agency;

            // ---- AGENCY cancellation throttle ----
            // Agencies are limited to 2 cancellations per day to prevent abuse.
            // We count directly from the reservations table — no extra model field needed.
            if ((string) $user->id !== (string) $client->id) {
                $agencyCancellationsToday = Reservation::where('agency_id', $agency->id)
                    ->where('cancelled_by', 'agency')
                    ->whereDate('cancelled_at', today())
                    ->count();

                if ($agencyCancellationsToday >= 2) {
                    return response()->json([
                        'message' => 'Your agency has reached its daily cancellation limit (2 per day).',
                    ], 403);
                }
            }

            // Refund the commission only when a confirmed reservation had already charged it.
            if ($reservation->status === 'confirmed') {
                $alreadyRefunded = Payment::where('reservation_id', $reservation->id)
                    ->where('type', 'refund')
                    ->exists();

                // Prevent duplicate refund records if the request is retried.
                if (!$alreadyRefunded) {
                    $commission = $reservation->total_amount * 0.1;
                    $before = $agency->balance;
                    $after = $before + $commission;

                    $agency->update([
                        'balance' => $after,
                    ]);

                    // Record the refund so the agency balance history remains auditable.
                    Payment::create([
                        'id' => Str::uuid(),
                        'reservation_id' => $reservation->id,
                        'agency_id' => $agency->id,
                        'amount' => $commission,
                        'type' => 'refund',
                        'status' => 'completed',
                        'balance_before' => $before,
                        'balance_after' => $after,
                        'reference' => 'REF-' . $reservation->id,
                    ]);
                }
            }

            // Mark the reservation as cancelled after any needed refund is handled.
            $reservation->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                // Store who cancelled so client and agency cancellations can be audited separately.
                'cancelled_by' => (string) $user->id === (string) $client->id ? 'client' : 'agency',
            ]);

            return response()->json([
                'message' => 'Reservation cancelled successfully',
            ]);
        });
    }

    // -------------------------------------------------------------------------
    // CLIENT: Confirm physical pickup of the car → trip officially starts
    // Only the client who made the reservation can call this, and only once
    // the agency has confirmed (status = confirmed). Pickup cannot be undone.
    // -------------------------------------------------------------------------
    public function pickup($id)
    {
        $user = auth()->user();

        // Only clients pick up cars — agency owners and admins have no pickup action.
        if ($user->role !== 'client') {
            return response()->json(['message' => 'Only clients can confirm pickup'], 403);
        }

        $reservation = Reservation::findOrFail($id);

        // The pickup must come from the client who made this reservation.
        if ((string) $reservation->client_id !== (string) $user->id) {
            return response()->json(['message' => 'You can only confirm pickup for your own reservations'], 403);
        }

        // The agency must have confirmed the reservation before the client can pick up.
        if ($reservation->status !== 'confirmed') {
            return response()->json([
                'message' => 'Pickup can only be confirmed for reservations that have been confirmed by the agency',
            ], 400);
        }

        // Prevent double-pickup if the client calls this endpoint twice.
        if ($reservation->picked_up_at) {
            return response()->json(['message' => 'Pickup already confirmed'], 400);
        }

        // Record the exact moment the client collected the car.
        // From this point the client has possession and the trip has started.
        $reservation->update(['picked_up_at' => now()]);

        return response()->json([
            'message'      => 'Pickup confirmed. Your trip has started!',
            'picked_up_at' => $reservation->picked_up_at,
        ]);
    }
}
