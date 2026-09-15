<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuickBooksSalesReceipt extends Model
{
    protected $table = 'quickbooks_sales_receipts';

    protected $fillable = [
        'realm_id',
        'qbo_id',
        'doc_number',
        'customer_name',
        'customer_qbo_id',
        'customer_email',
        'txn_date',
        'total_amount',
        'total_tax',
        'payment_method',
        'deposit_account_name',
        'currency_ref',
        'line_items',
        'synced_at',
    ];

    protected $casts = [
        'txn_date' => 'date',
        'total_amount' => 'decimal:2',
        'total_tax' => 'decimal:2',
        'line_items' => 'array',
        'synced_at' => 'datetime',
    ];
}
