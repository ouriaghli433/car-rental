<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;


class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    // Enable soft deletes because the users table has a deleted_at column.
    use HasFactory, Notifiable, HasApiTokens, SoftDeletes;
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = [
        'id',
        'first_name',
        'last_name',
        'email',
        'password',
        // Allow profile fields that already exist in the users migration.
        'phone',
        'avatar_url',
        'role',
        'status',
        // Allow cancellation throttling fields to be updated through model methods.
        'cancel_count_today',
        'blocked_until',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            // Cast the temporary block timestamp so date comparisons are reliable.
            'blocked_until' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function agency()
    {
        return $this->hasOne(Agency::class, 'owner_id');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class, 'client_id');
    }
}
