<?php

namespace App\Domain\Auth\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/** Storefront customer. */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'full_name', 'email', 'mobile', 'whatsapp', 'password', 'professional_type', 'speciality', 'gender',
        'business_name', 'date_of_birth', 'anniversary', 'council_reg_no', 'is_active', 'last_login_at',
    ];

    protected $hidden = ['password'];

    protected static function booted(): void
    {
        static::creating(function (self $user) {
            $user->referral_code ??= strtoupper(Str::random(8));
        });
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'mobile_verified_at' => 'datetime',
            'date_of_birth' => 'date',
            'anniversary' => 'date',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }
}
