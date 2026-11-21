<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuickBooksRefundReceipt extends Model
{
    protected $table = 'quickbooks_refund_receipts';

    protected $fillable = [
        'realm_id',
        'qbo_id',
        'doc_number',
        'customer_name',
        'customer_qbo_id',
        'txn_date',
        'total_amount',
        'payment_method',
        'account_name',
        'line_items',
        'currency_ref',
        'synced_at',
    ];

    protected $casts = [
        'txn_date' => 'date',
        'total_amount' => 'decimal:2',
        'line_items' => 'array',
        'synced_at' => 'datetime',
    ];
}
