<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuickBooksCreditMemo extends Model
{
    protected $table = 'quickbooks_credit_memos';

    protected $fillable = [
        'realm_id',
        'qbo_id',
        'doc_number',
        'customer_name',
        'customer_qbo_id',
        'txn_date',
        'total_amount',
        'remaining_credit',
        'total_tax',
        'currency_ref',
        'line_items',
        'synced_at',
    ];

    protected $casts = [
        'txn_date' => 'date',
        'total_amount' => 'decimal:2',
        'remaining_credit' => 'decimal:2',
        'total_tax' => 'decimal:2',
        'line_items' => 'array',
        'synced_at' => 'datetime',
    ];
}
