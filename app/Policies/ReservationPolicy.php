<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;
class ReservationPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // Allow the controller to list reservations only for known application roles.
        return in_array($user->role, ['admin', 'client', 'agency_owner'], true);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Reservation $reservation)
    {
        // Admins can inspect any reservation for support and moderation.
        if ($user->role === 'admin') {
            return true;
        }

        // Clients can inspect only their own reservations.
        if ((string) $reservation->client_id === (string) $user->id) {
            return true;
        }

        // Agency owners can inspect reservations that belong to their agency.
        return $user->role === 'agency_owner'
            && $user->agency
            && (string) $reservation->agency_id === (string) $user->agency->id;
    }
    public function cancel(User $user, Reservation $reservation)
    {
        // Clients can cancel their own reservations.
        if ((string) $reservation->client_id === (string) $user->id) {
            return true;
        }

        // Agency owners can cancel reservations that belong to their own agency.
        return $user->role === 'agency_owner'
            && $user->agency
            && (string) $reservation->agency_id === (string) $user->agency->id;
    }
    public function confirm(User $user, Reservation $reservation)
    {
        // Only agency owners can confirm bookings for their own agency.
        if ($user->role !== 'agency_owner') {
            return false;
        }

        if (!$user->agency) {
            return false;
        }

        return (string) $reservation->agency_id === (string) $user->agency->id;
    }
    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Reservation $reservation): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Reservation $reservation): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Reservation $reservation): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Reservation $reservation): bool
    {
        return false;
    }
}
