<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuickBooksTransaction extends Model
{
    protected $table = 'quickbooks_transactions';

    protected $fillable = [
        'realm_id',
        'qbo_id',
        'txn_type',
        'txn_date',
        'account_name',
        'entity_name',
        'amount',
        'description',
        'currency_ref',
        'synced_at',
    ];

    protected $casts = [
        'txn_date'  => 'date',
        'amount'    => 'decimal:2',
        'synced_at' => 'datetime',
    ];
}
