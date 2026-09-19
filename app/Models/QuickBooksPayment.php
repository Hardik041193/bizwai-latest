<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuickBooksPayment extends Model
{
    protected $table = 'quickbooks_payments';

    protected $fillable = [
        'realm_id',
        'qbo_id',
        'customer_name',
        'customer_qbo_id',
        'txn_date',
        'total_amount',
        'unapplied_amount',
        'payment_method',
        'payment_ref_number',
        'deposit_account_qbo_id',
        'deposit_account_name',
        'invoice_qbo_ids',
        'currency_ref',
        'synced_at',
    ];

    protected $casts = [
        'txn_date' => 'date',
        'total_amount' => 'decimal:2',
        'unapplied_amount' => 'decimal:2',
        'invoice_qbo_ids' => 'array',
        'synced_at' => 'datetime',
    ];
}
