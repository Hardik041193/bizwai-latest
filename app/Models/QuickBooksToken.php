<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuickBooksToken extends Model
{
    protected $table = 'quickbooks_tokens';

    protected $fillable = [
        'user_id',
        'realm_id',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'refresh_token_expires_at',
    ];

    protected $casts = [
        'access_token'              => 'encrypted',
        'refresh_token'             => 'encrypted',
        'token_expires_at'          => 'datetime',
        'refresh_token_expires_at'  => 'datetime',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Return the single "company" QuickBooks token — owned by any admin user.
     * Regular (non-admin) users see data through this shared company connection.
     */
    public static function getCompanyToken(): ?self
    {
        return self::whereHas('user', fn ($q) => $q->where('role', 'admin'))
            ->latest()
            ->first();
    }

    public function isAccessTokenExpired(): bool
    {
        if (is_null($this->token_expires_at)) {
            return true;
        }

        return $this->token_expires_at->subMinutes(5)->isPast();
    }

    public function isRefreshTokenExpired(): bool
    {
        if (is_null($this->refresh_token_expires_at)) {
            return false;
        }

        return $this->refresh_token_expires_at->isPast();
    }
}
