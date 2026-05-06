<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CarResource extends JsonResource
{
    // Transforms a Car model into a consistent JSON shape for all car endpoints.
    // Images and agency are eager-loaded by the controller before this runs.
    public function toArray($request): array
    {
        return [
            'id'             => $this->id,
            'brand'          => $this->brand,
            'model'          => $this->model,
            'year'           => $this->year,
            'color'          => $this->color,
            'plate_number'   => $this->plate_number,
            'type'           => $this->type,
            'transmission'   => $this->transmission,
            'seats'          => $this->seats,
            'price_per_day'  => $this->price_per_day,
            'description'    => $this->description,
            'status'         => $this->status,
            'avg_rating'     => $this->avg_rating,
            'total_reviews'  => $this->total_reviews,

            // All images for this car, flagging which one is the primary display image.
            'images' => $this->whenLoaded('images', fn() =>
                $this->images->map(fn($img) => [
                    'id'         => $img->id,
                    'image_url'  => $img->image_url,
                    'is_primary' => $img->is_primary,
                ])
            ),

            // Agency identity is intentionally hidden from the public listing.
            // Clients must complete a reservation before they see who the agency is.
            // This prevents clients from bypassing the platform to book directly.
            // Agency details are revealed in ReservationResource after confirmation.

            // City zone is the only location info exposed at browse time.
            // Clients can filter by city but cannot identify the specific agency.
            'city' => $this->whenLoaded('city', fn() => $this->city ? [
                'id'     => $this->city->id,
                'name'   => $this->city->name,
                'region' => $this->city->region,
            ] : null),
        ];
    }
}
