<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Review extends Model
{
    // Enable soft deletes because the reviews table has a deleted_at column.
    use SoftDeletes;

    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = [
        'id',
        'reservation_id',
        // Allow review scores and optional comments from the review workflow.
        'car_rating',
        'agency_rating',
        'comment',
    ];

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }
}
