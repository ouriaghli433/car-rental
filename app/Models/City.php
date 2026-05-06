<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = [
        'id',
        // Allow city catalog fields that already exist in the cities migration.
        'name',
        'region',
        'country',
        'is_active',
    ];

    // Cast active flag to boolean when cities are serialized or checked.
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
