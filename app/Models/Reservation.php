<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    //
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = [
        'id',
        'client_id',
        'car_id',
        'agency_id',
        'start_date',
        'end_date',
        'price_per_day_snapshot',
        'total_amount',
        'status',
        // Allow lifecycle metadata written by confirm/cancel/pickup workflows.
        'cancellation_reason',
        'cancelled_by',
        'confirmed_at',
        'picked_up_at',  // set when client confirms they have collected the car
        'expires_at',
        'cancelled_at',
        'completed_at',
    ];

    // Cast dates and money fields to predictable PHP types.
    protected function casts(): array
    {
        return [
            // datetime (not date) — rentals are 24-hour windows, e.g. 17:00 pickup → 17:00 return.
            'start_date' => 'datetime',
            'end_date'   => 'datetime',
            'price_per_day_snapshot' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'expires_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function client()
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function car()
    {
        return $this->belongsTo(Car::class);
    }

    public function agency()
    {
        return $this->belongsTo(Agency::class);
    }
    public function payment()
    {
        return $this->hasOne(Payment::class);
    }
    public function review()
    {
        return $this->hasOne(Review::class);
    }
}
