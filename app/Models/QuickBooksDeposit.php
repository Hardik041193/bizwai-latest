<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuickBooksDeposit extends Model
{
    protected $table = 'quickbooks_deposits';

    protected $fillable = [
        'realm_id',
        'qbo_id',
        'txn_date',
        'total_amount',
        'account_name',
        'account_qbo_id',
        'line_count',
        'description',
        'currency_ref',
        'synced_at',
    ];

    protected $casts = [
        'txn_date' => 'date',
        'total_amount' => 'decimal:2',
        'synced_at' => 'datetime',
    ];
}
