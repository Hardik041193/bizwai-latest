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
        'selected_clients',
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
        'selected_clients'          => 'array',
    ];

    /**
     * Whether the user has finished the client-selection step at all.
     * (Completing it with no specific clients means "All clients".)
     */
    public function hasCompletedClientSelection(): bool
    {
        return $this->client_selected_at !== null;
    }

    /**
     * True when the selection step is done but no specific client was chosen,
     * i.e. the user opted to track every client (no filtering should apply).
     */
    public function isAllClientsSelected(): bool
    {
        return $this->hasCompletedClientSelection()
            && empty($this->selectedClients());
    }

    /**
     * Whether data should be filtered to one or more specific clients.
     */
    public function hasSelectedClient(): bool
    {
        return $this->hasCompletedClientSelection()
            && ! empty($this->selectedClients());
    }

    /**
     * Normalised list of selected clients: [['qbo_id' => ..., 'name' => ...], ...].
     * Falls back to the legacy single-column selection for older records.
     *
     * @return array<int, array{qbo_id: string, name: string|null}>
     */
    public function selectedClients(): array
    {
        $clients = $this->selected_clients;

        if (is_array($clients) && count($clients) > 0) {
            return $clients;
        }

        if ($this->selected_client_qbo_id !== null) {
            return [[
                'qbo_id' => $this->selected_client_qbo_id,
                'name'   => $this->selected_client_name,
            ]];
        }

        return [];
    }

    /**
     * Names of the selected clients, used to filter synced data by customer name.
     *
     * @return array<int, string>
     */
    public function selectedClientNames(): array
    {
        return array_values(array_filter(array_map(
            fn ($client) => $client['name'] ?? null,
            $this->selectedClients()
        )));
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
