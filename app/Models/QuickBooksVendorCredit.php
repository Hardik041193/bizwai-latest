<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuickBooksVendorCredit extends Model
{
    protected $table = 'quickbooks_vendor_credits';

    protected $fillable = [
        'realm_id',
        'qbo_id',
        'doc_number',
        'vendor_name',
        'vendor_qbo_id',
        'txn_date',
        'total_amount',
        'balance',
        'currency_ref',
        'synced_at',
    ];

    protected $casts = [
        'txn_date' => 'date',
        'total_amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'synced_at' => 'datetime',
    ];
}
