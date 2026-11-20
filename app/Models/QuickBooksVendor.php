<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuickBooksVendor extends Model
{
    protected $table = 'quickbooks_vendors';

    protected $fillable = [
        'realm_id',
        'qbo_id',
        'display_name',
        'company_name',
        'email',
        'phone',
        'balance',
        'active',
        'currency_ref',
        'synced_at',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'active' => 'boolean',
        'synced_at' => 'datetime',
    ];
}
