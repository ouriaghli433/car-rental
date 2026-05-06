<?php

namespace App\Http\Controllers;

use App\Http\Resources\AgencyResource;
use App\Models\Agency;
use App\Models\Car;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    // -------------------------------------------------------------------------
    // SHARED GUARD: Abort immediately if the caller is not an admin.
    // All methods in this controller call this first to keep the guard DRY.
    // -------------------------------------------------------------------------
    private function requireAdmin(): void
    {
        if (auth()->user()->role !== 'admin') {
            abort(403, 'Admin access required');
        }
    }

    // -------------------------------------------------------------------------
    // ADMIN: List all users (paginated)
    // Pass ?withTrashed=1 to include soft-deleted accounts.
    // -------------------------------------------------------------------------
    public function users(Request $request)
    {
        $this->requireAdmin();

        $query = $request->boolean('withTrashed')
            ? User::withTrashed()  // include soft-deleted users for recovery/audit
            : User::query();

        $users = $query->latest()->paginate(20);

        return response()->json($users);
    }

    // -------------------------------------------------------------------------
    // ADMIN: Change a user's account status (active, blocked, pending)
    // Used to block abusive clients or re-activate suspended accounts.
    // -------------------------------------------------------------------------
    public function updateUserStatus(Request $request, $id)
    {
        $this->requireAdmin();

        $data = $request->validate([
            'status' => 'required|in:active,blocked,pending',
        ]);

        $user = User::findOrFail($id);

        // Cast both sides to string because auth()->id() can return a LazyUuid object.
        if ((string) $user->id === (string) auth()->id()) {
            return response()->json(['message' => 'You cannot change your own status'], 400);
        }

        $user->update(['status' => $data['status']]);

        return response()->json([
            'message' => "User status updated to {$data['status']}",
        ]);
    }

    // -------------------------------------------------------------------------
    // ADMIN: List all agencies (any status) with their owner and city
    // Needed for the approval workflow — admins see pending agencies here.
    // -------------------------------------------------------------------------
    public function agencies(Request $request)
    {
        $this->requireAdmin();

        // Allow filtering by status so the admin can see only pending agencies.
        $query = Agency::with(['owner', 'city'])->withCount('cars');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $agencies = $query->latest()->paginate(20);

        return AgencyResource::collection($agencies);
    }

    // -------------------------------------------------------------------------
    // ADMIN: Approve or reject an agency (approved, rejected, pending)
    // Approving an agency allows it to receive reservations and list cars.
    // -------------------------------------------------------------------------
    public function updateAgencyStatus(Request $request, $id)
    {
        $this->requireAdmin();

        $data = $request->validate([
            'status' => 'required|in:approved,rejected,pending',
        ]);

        $agency = Agency::findOrFail($id);
        $agency->update(['status' => $data['status']]);

        return response()->json([
            'message' => "Agency status updated to {$data['status']}",
        ]);
    }

    // -------------------------------------------------------------------------
    // ADMIN: Approve a pending agency profile update
    // Applies the pending_changes fields onto the live agency record and clears the queue.
    // -------------------------------------------------------------------------
    public function approveAgencyChanges($id)
    {
        $this->requireAdmin();

        $agency = Agency::findOrFail($id);

        if (empty($agency->pending_changes)) {
            return response()->json(['message' => 'No pending changes to approve'], 400);
        }

        // Apply every field from the pending request to the live agency record,
        // then clear pending_changes so the queue is empty again.
        // Catch unique constraint violations — another agency may have taken the name/email
        // between when the owner submitted the change and when the admin approved it.
        try {
            $agency->update(array_merge($agency->pending_changes, ['pending_changes' => null]));
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'message' => 'Cannot apply changes — a name or email conflict now exists. Reject the request and ask the owner to resubmit with a different value.',
            ], 409);
        }

        return response()->json([
            'message' => 'Agency changes approved and applied',
            'data'    => $agency->fresh(['city']),
        ]);
    }

    // -------------------------------------------------------------------------
    // ADMIN: Reject a pending agency profile update
    // Discards the pending_changes without touching the live agency record.
    // -------------------------------------------------------------------------
    public function rejectAgencyChanges($id)
    {
        $this->requireAdmin();

        $agency = Agency::findOrFail($id);

        if (empty($agency->pending_changes)) {
            return response()->json(['message' => 'No pending changes to reject'], 400);
        }

        $agency->update(['pending_changes' => null]);

        return response()->json(['message' => 'Agency changes rejected and discarded']);
    }

    // -------------------------------------------------------------------------
    // ADMIN: Add funds to an agency's balance (top-up)
    // Records a payment entry so the balance change is auditable.
    // -------------------------------------------------------------------------
    public function topUpBalance(Request $request, $id)
    {
        $this->requireAdmin();

        $data = $request->validate([
            // Top-up amount must be positive to prevent accidental deductions.
            'amount' => 'required|numeric|min:0.01',
        ]);

        // Wrap balance update and payment record in a transaction to keep them atomic.
        return DB::transaction(function () use ($data, $id) {
            // Lock the agency row so concurrent top-ups don't race on the balance.
            $agency = Agency::lockForUpdate()->findOrFail($id);

            $before = $agency->balance;
            $after  = $before + $data['amount'];

            $agency->update(['balance' => $after]);

            // Record the top-up as a payment so admins can audit all balance changes.
            Payment::create([
                'id'             => Str::uuid(),
                'reservation_id' => null,  // top-ups are not tied to a specific reservation
                'agency_id'      => $agency->id,
                'amount'         => $data['amount'],
                // 'top_up' matches the enum value defined in the payments migration.
                'type'           => 'top_up',
                'status'         => 'completed',
                'balance_before' => $before,
                'balance_after'  => $after,
                'reference'      => 'TOPUP-' . strtoupper(Str::random(8)),
            ]);

            return response()->json([
                'message'         => 'Balance topped up successfully',
                'balance_before'  => $before,
                'balance_after'   => $after,
            ]);
        });
    }

    // -------------------------------------------------------------------------
    // ADMIN: Platform-wide statistics dashboard
    // Returns key counts and revenue figures for the admin overview page.
    // -------------------------------------------------------------------------
    public function stats()
    {
        $this->requireAdmin();

        // Count reservations broken down by status so the admin can see platform health.
        $reservationsByStatus = Reservation::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        // Total revenue = sum of all commission payments (not refunds or top-ups).
        $totalRevenue = Payment::where('type', 'commission')
            ->where('status', 'completed')
            ->sum('amount');

        return response()->json([
            'users' => [
                'total'   => User::count(),
                'active'  => User::where('status', 'active')->count(),
                'blocked' => User::where('status', 'blocked')->count(),
            ],
            'agencies' => [
                'total'    => Agency::count(),
                'approved' => Agency::where('status', 'approved')->count(),
                'pending'  => Agency::where('status', 'pending')->count(),
                'rejected' => Agency::where('status', 'rejected')->count(),
            ],
            'cars' => [
                'total'       => Car::count(),
                'available'   => Car::where('status', 'available')->count(),
                'rented'      => Car::where('status', 'rented')->count(),
                'maintenance' => Car::where('status', 'maintenance')->count(),
            ],
            // Each reservation status gets its own counter so the admin can spot backlogs.
            'reservations' => $reservationsByStatus,
            // Platform revenue comes from the 10% commission charged per confirmed reservation.
            'total_revenue' => $totalRevenue,
        ]);
    }
}
