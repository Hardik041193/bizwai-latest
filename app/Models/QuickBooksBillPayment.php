<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuickBooksBillPayment extends Model
{
    protected $table = 'quickbooks_bill_payments';

    protected $fillable = [
        'realm_id',
        'qbo_id',
        'doc_number',
        'vendor_name',
        'vendor_qbo_id',
        'txn_date',
        'total_amount',
        'pay_type',
        'account_name',
        'bill_qbo_ids',
        'currency_ref',
        'synced_at',
    ];

    protected $casts = [
        'txn_date' => 'date',
        'total_amount' => 'decimal:2',
        'bill_qbo_ids' => 'array',
        'synced_at' => 'datetime',
    ];
}
