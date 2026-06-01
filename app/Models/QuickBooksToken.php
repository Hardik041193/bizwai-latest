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
        'company_name',
        'legal_name',
        'company_email',
        'country',
        'selected_client_qbo_id',
        'selected_client_name',
        'client_selected_at',
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
        'client_selected_at'        => 'datetime',
    ];

    public function hasSelectedClient(): bool
    {
        return $this->client_selected_at !== null
            && $this->selected_client_qbo_id !== null;
    }

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
