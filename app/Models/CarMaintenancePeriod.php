<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarMaintenancePeriod extends Model
{
    // Match the UUID primary key used by the car_maintenance_periods migration.
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'car_id',
        // Store the blocked maintenance date window for booking availability checks.
        'start_date',
        'end_date',
        'reason',
        'status',
    ];

    // Cast maintenance window dates to date objects for consistent comparisons.
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function car()
    {
        return $this->belongsTo(Car::class);
    }
}
