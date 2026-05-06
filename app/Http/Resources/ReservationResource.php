<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReservationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request)
    {
        $showAgency = in_array($this->status, ['confirmed', 'completed']);

        return [
            'id'           => $this->id,
            'status'       => $this->status,
            'start_date'   => $this->start_date,
            'end_date'     => $this->end_date,
            'total_amount' => $this->total_amount,

            // Lifecycle timestamps — clients need these to know their reservation state.
            'confirmed_at' => $this->confirmed_at,
            // Pending reservations expire if not confirmed within one hour.
            'expires_at'   => $this->expires_at,
            // Set when the client physically collects the car.
            'picked_up_at' => $this->picked_up_at,
            'completed_at' => $this->completed_at,
            'cancelled_at' => $this->cancelled_at,
            // Who cancelled and why — clients need this to understand what happened.
            'cancelled_by'         => $this->cancelled_by,
            'cancellation_reason'  => $this->cancellation_reason,

            'car' => [
                'brand'         => $this->car?->brand,
                'model'         => $this->car?->model,
                'price_per_day' => $this->car?->price_per_day,
                'image'         => $this->car?->images
                    ?->where('is_primary', true)
                    ->first()?->image_url,
            ],

            'city' => $this->agency?->city?->name,

            // Agency contact details are hidden until the reservation is confirmed or completed.
            // This prevents clients from bypassing the platform to book directly.
            'agency' => $showAgency ? [
                'name'    => $this->agency?->name,
                'phone'   => $this->agency?->phone,
                'address' => $this->agency?->address,
            ] : null,
        ];
    }
}
