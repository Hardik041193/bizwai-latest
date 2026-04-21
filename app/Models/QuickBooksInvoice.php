<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuickBooksInvoice extends Model
{
    protected $table = 'quickbooks_invoices';

    protected $fillable = [
        'realm_id',
        'qbo_id',
        'doc_number',
        'customer_name',
        'customer_email',
        'txn_date',
        'due_date',
        'total_amount',
        'balance',
        'status',
        'currency_ref',
        'line_items',
        'synced_at',
    ];

    protected $casts = [
        'txn_date'     => 'date',
        'due_date'     => 'date',
        'total_amount' => 'decimal:2',
        'balance'      => 'decimal:2',
        'line_items'   => 'array',
        'synced_at'    => 'datetime',
    ];
}
