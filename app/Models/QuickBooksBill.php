<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuickBooksBill extends Model
{
    protected $table = 'quickbooks_bills';

    protected $fillable = [
        'realm_id',
        'qbo_id',
        'doc_number',
        'vendor_name',
        'vendor_qbo_id',
        'txn_date',
        'due_date',
        'total_amount',
        'balance',
        'status',
        'ap_account_name',
        'description',
        'currency_ref',
        'line_items',
        'synced_at',
    ];

    protected $casts = [
        'txn_date' => 'date',
        'due_date' => 'date',
        'total_amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'line_items' => 'array',
        'synced_at' => 'datetime',
    ];
}
