<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Car extends Model
{
    // Enable soft deletes because the cars table has a deleted_at column.
    use SoftDeletes;

    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = [
        'id',
        'agency_id',
        'city_id',
        // Allow car identity fields used by listings and reservation snapshots.
        'brand',
        'model',
        'year',
        'color',
        'plate_number',
        // Allow rental configuration fields used by search and pricing.
        'type',
        'transmission',
        'seats',
        'price_per_day',
        'description',
        'status',
        // Allow rating counters to be maintained from review workflows later.
        'avg_rating',
        'total_reviews',
    ];

    // Cast money and rating fields to predictable PHP types.
    protected function casts(): array
    {
        return [
            'price_per_day' => 'decimal:2',
            'avg_rating' => 'float',
        ];
    }

    public function agency()
    {
        return $this->belongsTo(Agency::class);
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }
    public function maintenancePeriods()
    {
        return $this->hasMany(CarMaintenancePeriod::class);
    }
    public function city()
    {
        return $this->belongsTo(\App\Models\City::class);
    }
    public function images()
    {
        return $this->hasMany(CarImage::class);
    }

    // Reviews are linked through reservations: a review belongs to a reservation which belongs to a car.
    // hasManyThrough lets us load all reviews for a car in one query without a manual join.
    public function reviews()
    {
        return $this->hasManyThrough(
            \App\Models\Review::class,
            \App\Models\Reservation::class,
            'car_id',         // foreign key on reservations pointing to this car
            'reservation_id', // foreign key on reviews pointing to the reservation
        );
    }
}
