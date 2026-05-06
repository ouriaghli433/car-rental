<?php

namespace App\Http\Controllers;

use App\Http\Resources\CarResource;
use App\Models\Car;
use App\Models\CarImage;
use App\Models\CarMaintenancePeriod;
use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CarController extends Controller
{
    // -------------------------------------------------------------------------
    // PUBLIC: List cars with optional filters
    // Anyone (even unauthenticated) can browse the car catalog.
    // -------------------------------------------------------------------------
    public function index(Request $request)
    {
        // 'agency' is intentionally not eager-loaded here — CarResource hides it from
        // the public listing. Loading it would waste a JOIN on every car listing request.
        $query = Car::with(['images', 'city'])
            ->whereHas('agency', fn($q) => $q->where('status', 'approved'));

        // Filter by city if the caller wants cars in a specific location.
        if ($request->filled('city_id')) {
            $query->where('city_id', $request->city_id);
        }

        // Filter by vehicle type (sedan, suv, van).
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // Filter by transmission preference (automatic, manual).
        if ($request->filled('transmission')) {
            $query->where('transmission', $request->transmission);
        }

        // Filter by price range (either bound is optional).
        if ($request->filled('min_price')) {
            $query->where('price_per_day', '>=', $request->min_price);
        }
        if ($request->filled('max_price')) {
            $query->where('price_per_day', '<=', $request->max_price);
        }

        // Filter by availability: exclude cars that have a confirmed/pending reservation
        // or a scheduled maintenance period overlapping the requested dates.
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $start = $request->start_date;
            $end   = $request->end_date;

            // Exclude cars with active reservations in this window.
            $query->whereDoesntHave('reservations', function ($q) use ($start, $end) {
                $q->whereIn('status', ['pending', 'confirmed'])
                  ->where('start_date', '<', $end)
                  ->where('end_date', '>', $start);
            });

            // Exclude cars under scheduled maintenance in this window.
            $query->whereDoesntHave('maintenancePeriods', function ($q) use ($start, $end) {
                $q->where('status', 'scheduled')
                  ->where('start_date', '<', $end)
                  ->where('end_date', '>', $start);
            });
        }

        $cars = $query->latest()->paginate(15);

        return CarResource::collection($cars);
    }

    // -------------------------------------------------------------------------
    // PUBLIC: Show a single car with full details
    // -------------------------------------------------------------------------
    public function show($id)
    {
        // Load all relationships needed to build the full car detail page.
        $car = Car::with(['images', 'agency.city', 'city', 'maintenancePeriods'])
            ->findOrFail($id);

        return new CarResource($car);
    }

    // -------------------------------------------------------------------------
    // AGENCY OWNER: Create a new car under their agency
    // The agency must be approved before new cars can be listed.
    // -------------------------------------------------------------------------
    public function store(Request $request)
    {
        $user = auth()->user();

        // Only agency owners can add cars to the platform.
        if ($user->role !== 'agency_owner') {
            return response()->json(['message' => 'Only agency owners can create cars'], 403);
        }

        $agency = $user->agency;

        // The owner must have an existing agency before adding cars.
        if (!$agency) {
            return response()->json(['message' => 'You must create an agency first'], 400);
        }

        // Unapproved agencies cannot list cars — wait for admin approval.
        if ($agency->status !== 'approved') {
            return response()->json(['message' => 'Your agency must be approved before adding cars'], 403);
        }

        $data = $request->validate([
            'brand'         => 'required|string|max:100',
            'model'         => 'required|string|max:100',
            'year'          => 'required|integer|min:1990|max:' . (date('Y') + 1),
            'color'         => 'required|string|max:50',
            // plate_number must be unique across the entire fleet.
            'plate_number'  => 'required|string|unique:cars,plate_number',
            'type'          => 'required|in:sedan,suv,van',
            'transmission'  => 'required|in:automatic,manual',
            'seats'         => 'required|integer|min:1|max:20',
            'price_per_day' => 'required|numeric|min:1',
            'description'   => 'nullable|string',
        ]);

        // Inherit city from the agency so cars are always discoverable in the right city.
        $car = Car::create(array_merge($data, [
            'id'        => Str::uuid(),
            'agency_id' => $agency->id,
            'city_id'   => $agency->city_id,
            'status'    => 'available',
        ]));

        return response()->json([
            'message' => 'Car created successfully',
            'data'    => new CarResource($car->load(['images', 'agency', 'city'])),
        ], 201);
    }

    // -------------------------------------------------------------------------
    // AGENCY OWNER: Update car details (only their own cars via CarPolicy)
    // -------------------------------------------------------------------------
    public function update(Request $request, $id)
    {
        $car = Car::findOrFail($id);

        // CarPolicy checks that the authenticated user owns the agency this car belongs to.
        $this->authorize('update', $car);

        $data = $request->validate([
            'brand'         => 'sometimes|string|max:100',
            'model'         => 'sometimes|string|max:100',
            'year'          => 'sometimes|integer|min:1990|max:' . (date('Y') + 1),
            'color'         => 'sometimes|string|max:50',
            // Ignore the current car's own plate so the unique rule doesn't reject it.
            'plate_number'  => 'sometimes|string|unique:cars,plate_number,' . $car->id,
            'type'          => 'sometimes|in:sedan,suv,van',
            'transmission'  => 'sometimes|in:automatic,manual',
            'seats'         => 'sometimes|integer|min:1|max:20',
            'price_per_day' => 'sometimes|numeric|min:1',
            'description'   => 'nullable|string',
            'status'        => 'sometimes|in:available,rented,maintenance',
        ]);

        $car->update($data);

        return response()->json([
            'message' => 'Car updated successfully',
            'data'    => new CarResource($car->load(['images', 'agency', 'city'])),
        ]);
    }

    // -------------------------------------------------------------------------
    // AGENCY OWNER: Soft-delete a car (only their own cars via CarPolicy)
    // Blocked if the car has active (pending/confirmed) reservations.
    // -------------------------------------------------------------------------
    public function destroy($id)
    {
        $car = Car::findOrFail($id);

        // CarPolicy checks agency ownership before we touch anything.
        $this->authorize('delete', $car);

        // Prevent deleting a car that clients are currently relying on.
        $hasActiveReservations = Reservation::where('car_id', $car->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->exists();

        if ($hasActiveReservations) {
            return response()->json([
                'message' => 'Cannot delete a car with active reservations',
            ], 400);
        }

        // Soft delete preserves the car record for historical reservation data.
        $car->delete();

        return response()->json(['message' => 'Car deleted successfully']);
    }

    // -------------------------------------------------------------------------
    // AGENCY OWNER: Add an image to one of their cars
    // If is_primary is true, all other images for this car are demoted first.
    // -------------------------------------------------------------------------
    public function addImage(Request $request, $id)
    {
        $car = Car::findOrFail($id);

        // CarPolicy checks agency ownership.
        $this->authorize('addImage', $car);

        $data = $request->validate([
            'image_url'  => 'required|url',
            'is_primary' => 'boolean',
        ]);

        // Wrap in a transaction so the demotion and insert are atomic.
        $image = DB::transaction(function () use ($car, $data) {
            // If the new image is set as primary, demote all existing primary images first.
            if (!empty($data['is_primary'])) {
                CarImage::where('car_id', $car->id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            return CarImage::create([
                'id'         => Str::uuid(),
                'car_id'     => $car->id,
                'image_url'  => $data['image_url'],
                'is_primary' => $data['is_primary'] ?? false,
            ]);
        });

        return response()->json([
            'message' => 'Image added successfully',
            'data'    => [
                'id'         => $image->id,
                'image_url'  => $image->image_url,
                'is_primary' => $image->is_primary,
            ],
        ], 201);
    }

    // -------------------------------------------------------------------------
    // AGENCY OWNER: Remove an image from one of their cars
    // -------------------------------------------------------------------------
    public function removeImage($id, $imageId)
    {
        $car = Car::findOrFail($id);

        // CarPolicy checks agency ownership.
        $this->authorize('removeImage', $car);

        // Confirm the image actually belongs to this car before deleting.
        $image = CarImage::where('id', $imageId)
            ->where('car_id', $car->id)
            ->firstOrFail();

        $image->delete();

        return response()->json(['message' => 'Image removed successfully']);
    }

    // -------------------------------------------------------------------------
    // AGENCY OWNER: Schedule a maintenance period for one of their cars
    // Blocked if an active reservation already covers those dates.
    // -------------------------------------------------------------------------
    public function addMaintenance(Request $request, $id)
    {
        $car = Car::findOrFail($id);

        // CarPolicy checks agency ownership.
        $this->authorize('addMaintenance', $car);

        $data = $request->validate([
            // Maintenance is scheduled by calendar day (no time component needed).
            'start_date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'end_date'   => 'required|date_format:Y-m-d|after:start_date',
            'reason'     => 'nullable|string|max:500',
        ]);

        // Wrap the overlap check and insert in a transaction so a concurrent reservation
        // request cannot slip in between the check and the maintenance period creation.
        return DB::transaction(function () use ($car, $data) {
            // Lock the car row to block concurrent reservation and maintenance inserts.
            Car::whereKey($car->id)->lockForUpdate()->first();

            // Prevent scheduling maintenance over dates a client has already booked.
            $reservationOverlap = Reservation::where('car_id', $car->id)
                ->whereIn('status', ['pending', 'confirmed'])
                ->where('start_date', '<', $data['end_date'])
                ->where('end_date', '>', $data['start_date'])
                ->exists();

            if ($reservationOverlap) {
                return response()->json([
                    'message' => 'Cannot schedule maintenance during an active reservation',
                ], 400);
            }

            $period = CarMaintenancePeriod::create([
                'id'         => Str::uuid(),
                'car_id'     => $car->id,
                'start_date' => $data['start_date'],
                'end_date'   => $data['end_date'],
                'reason'     => $data['reason'] ?? null,
                'status'     => 'scheduled',
            ]);

            return response()->json([
                'message' => 'Maintenance period scheduled',
                'data'    => $period,
            ], 201);
        });
    }

    // -------------------------------------------------------------------------
    // AGENCY OWNER: Remove a scheduled maintenance period from one of their cars
    // -------------------------------------------------------------------------
    public function removeMaintenance($id, $periodId)
    {
        $car = Car::findOrFail($id);

        // CarPolicy checks agency ownership.
        $this->authorize('removeMaintenance', $car);

        // Confirm the period actually belongs to this car before deleting.
        $period = CarMaintenancePeriod::where('id', $periodId)
            ->where('car_id', $car->id)
            ->firstOrFail();

        $period->delete();

        return response()->json(['message' => 'Maintenance period removed']);
    }
}
