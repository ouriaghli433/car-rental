<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    //
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = [
        'id',
        'reservation_id',
        'agency_id',
        'amount',
        'type',
        'status',
        'balance_before',
        'balance_after',
        'reference',
    ];

    // Cast ledger amounts to decimals so API responses keep money precision.
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }
    public function agency()
    {
        return $this->belongsTo(Agency::class);
    }
}
