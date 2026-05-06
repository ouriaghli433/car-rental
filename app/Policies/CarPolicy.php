<?php

namespace App\Policies;

use App\Models\Car;
use App\Models\User;

class CarPolicy
{
    // Only the agency owner who owns this car's agency can modify it.
    // We check both the role and that the car's agency_id matches the user's agency.
    private function ownsCarAgency(User $user, Car $car): bool
    {
        // Cast both sides to string — agency_id comes from DB as a string while
        // $user->agency->id may be a LazyUuidFromString object when freshly created.
        return $user->role === 'agency_owner'
            && $user->agency !== null
            && (string) $car->agency_id === (string) $user->agency->id;
    }

    // Agency owners can update car details (price, description, status, etc.)
    public function update(User $user, Car $car): bool
    {
        return $this->ownsCarAgency($user, $car);
    }

    // Agency owners can soft-delete their own cars (blocked in controller if reservations exist)
    public function delete(User $user, Car $car): bool
    {
        return $this->ownsCarAgency($user, $car);
    }

    // Agency owners can add images to their own cars
    public function addImage(User $user, Car $car): bool
    {
        return $this->ownsCarAgency($user, $car);
    }

    // Agency owners can remove images from their own cars
    public function removeImage(User $user, Car $car): bool
    {
        return $this->ownsCarAgency($user, $car);
    }

    // Agency owners can schedule maintenance for their own cars
    public function addMaintenance(User $user, Car $car): bool
    {
        return $this->ownsCarAgency($user, $car);
    }

    // Agency owners can remove maintenance periods from their own cars
    public function removeMaintenance(User $user, Car $car): bool
    {
        return $this->ownsCarAgency($user, $car);
    }
}
