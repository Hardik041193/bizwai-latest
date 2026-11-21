<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuickBooksTransfer extends Model
{
    protected $table = 'quickbooks_transfers';

    protected $fillable = [
        'realm_id',
        'qbo_id',
        'txn_date',
        'amount',
        'from_account_name',
        'to_account_name',
        'description',
        'currency_ref',
        'synced_at',
    ];

    protected $casts = [
        'txn_date' => 'date',
        'amount' => 'decimal:2',
        'synced_at' => 'datetime',
    ];
}
