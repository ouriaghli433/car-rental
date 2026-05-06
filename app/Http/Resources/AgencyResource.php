<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AgencyResource extends JsonResource
{
    // Transforms an Agency model into a consistent JSON shape for all agency endpoints.
    // Sensitive fields (balance, owner, pending_changes) are gated by role.
    public function toArray($request): array
    {
        $isAdmin  = $request->user()?->role === 'admin';
        $isOwner  = $request->user() && (string) $this->owner_id === (string) $request->user()->id;

        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'slug'          => $this->slug,
            'description'   => $this->description,
            'address'       => $this->address,
            'phone'         => $this->phone,
            'email'         => $this->email,
            'logo_url'      => $this->logo_url,
            'status'        => $this->status,
            'avg_rating'    => $this->avg_rating,
            'total_reviews' => $this->total_reviews,

            // Balance is financially sensitive — only admins should see it.
            'balance' => $isAdmin ? $this->balance : null,

            // Owner details are only useful for admin oversight, not public listings.
            'owner' => $isAdmin && $this->relationLoaded('owner') && $this->owner ? [
                'id'         => $this->owner->id,
                'first_name' => $this->owner->first_name,
                'last_name'  => $this->owner->last_name,
            ] : null,

            // Flag is always visible so clients know their update is queued.
            'has_pending_changes' => !empty($this->pending_changes),

            // Full pending_changes payload is visible to the owner (so they know what's queued)
            // and to admins (so they can review and approve or reject).
            'pending_changes' => ($isAdmin || $isOwner) ? $this->pending_changes : null,

            // City where the agency operates.
            'city' => $this->whenLoaded('city', fn() => $this->city ? [
                'id'     => $this->city->id,
                'name'   => $this->city->name,
                'region' => $this->city->region,
            ] : null),

            // Car count is computed in the controller via withCount() for efficiency.
            'car_count' => $this->cars_count ?? null,
        ];
    }
}
