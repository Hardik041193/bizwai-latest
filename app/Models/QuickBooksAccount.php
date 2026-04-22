<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuickBooksAccount extends Model
{
    protected $table = 'quickbooks_accounts';

    protected $fillable = [
        'realm_id',
        'qbo_id',
        'name',
        'account_type',
        'account_sub_type',
        'classification',
        'current_balance',
        'currency_ref',
        'active',
        'synced_at',
    ];

    protected $casts = [
        'current_balance' => 'decimal:2',
        'active'          => 'boolean',
        'synced_at'       => 'datetime',
    ];
}
