<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'role',
        'phone', 'job_title', 'address', 'bio', 'avatar',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password'          => 'hashed',
    ];

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isVerified(): bool
    {
        return ! is_null($this->email_verified_at);
    }

    /**
     * Returns the public URL for the user's avatar, or null if none is set.
     */
    public function avatarUrl(): ?string
    {
        if (! $this->avatar) {
            return null;
        }

        // Already an absolute URL (e.g. OAuth provider picture)
        if (str_starts_with($this->avatar, 'http')) {
            return $this->avatar;
        }

        return \Illuminate\Support\Facades\Storage::disk('public')->url($this->avatar);
    }

    /**
     * Serialise the user as a profile resource (safe fields only).
     */
    public function profileResource(): array
    {
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'email'             => $this->email,
            'role'              => $this->role,
            'phone'             => $this->phone,
            'job_title'         => $this->job_title,
            'address'           => $this->address,
            'bio'               => $this->bio,
            'avatar_url'        => $this->avatarUrl(),
            'email_verified_at' => $this->email_verified_at,
            'created_at'        => $this->created_at,
        ];
    }

    public function quickBooksToken(): HasOne
    {
        return $this->hasOne(QuickBooksToken::class);
    }

    public function hasQuickBooksConnected(): bool
    {
        return $this->quickBooksToken()->exists();
    }
}
