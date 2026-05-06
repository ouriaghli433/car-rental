<?php

namespace App\Policies;

use App\Models\Agency;
use App\Models\User;

class AgencyPolicy
{
    // Only the owner of the agency can update it.
    // We compare the agency's owner_id directly to the authenticated user's id.
    public function update(User $user, Agency $agency): bool
    {
        // Cast both sides to string — owner_id comes from DB as a string while
        // $user->id may be a LazyUuidFromString object when freshly created.
        return $user->role === 'agency_owner'
            && (string) $agency->owner_id === (string) $user->id;
    }
}
