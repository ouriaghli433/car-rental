<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    // Transforms a Review model into a consistent JSON shape.
    // The reservation→client chain must be eager-loaded by the controller.
    public function toArray($request): array
    {
        // Navigate through the reservation to get the client who wrote this review.
        $client = $this->reservation?->client;

        return [
            'id'             => $this->id,
            'car_rating'     => $this->car_rating,
            'agency_rating'  => $this->agency_rating,
            'comment'        => $this->comment,
            'created_at'     => $this->created_at,

            // Show the reviewer's name so the public can see who left the review.
            'client' => $client ? [
                'first_name' => $client->first_name,
                'last_name'  => $client->last_name,
            ] : null,
        ];
    }
}
