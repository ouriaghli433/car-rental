<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarImage extends Model
{
    // Match the UUID primary key used by the car_images migration.
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = [
        'id',
        'car_id',
        // Allow image URL and primary marker used by the reservation resource.
        'image_url',
        'is_primary',
    ];

    // Cast primary marker to boolean when images are serialized or filtered.
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function car()
    {
        return $this->belongsTo(Car::class);
    }
}
