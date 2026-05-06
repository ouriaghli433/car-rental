<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Agency extends Model
{
    // Enable soft deletes because the agencies table has a deleted_at column.
    use SoftDeletes;

    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = [
        'id',
        'owner_id',
        'city_id',
        'logo_url',
        'name',
        'slug',
        'description',
        'address',
        'phone',
        'email',
        'status',
        'balance',
        'avg_rating',
        'total_reviews',
        // JSON blob of field changes waiting for admin approval.
        'pending_changes',
    ];

    protected function casts(): array
    {
        return [
            // Deserialize the JSON blob automatically so controllers get an array, not a string.
            'pending_changes' => 'array',
        ];
    }
    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
    public function cars()
    {
        return $this->hasMany(Car::class);
    }
    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }
    public function city()
    {
        return $this->belongsTo(\App\Models\City::class);
    }

    // Reviews are linked through reservations: a review belongs to a reservation which belongs to an agency.
    // hasManyThrough lets us load all reviews for an agency in one query without a manual join.
    public function reviews()
    {
        return $this->hasManyThrough(
            \App\Models\Review::class,
            \App\Models\Reservation::class,
            'agency_id',      // foreign key on reservations pointing to this agency
            'reservation_id', // foreign key on reviews pointing to the reservation
        );
    }
}
