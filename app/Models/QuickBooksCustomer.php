<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuickBooksCustomer extends Model
{
    protected $table = 'quickbooks_customers';

    protected $fillable = [
        'realm_id',
        'qbo_id',
        'display_name',
        'company_name',
        'email',
        'phone',
        'balance',
        'active',
        'synced_at',
    ];

    protected $casts = [
        'balance'   => 'decimal:2',
        'active'    => 'boolean',
        'synced_at' => 'datetime',
    ];
}
