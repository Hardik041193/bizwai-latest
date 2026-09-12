<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Filtering synced records by customer name breaks silently when a QBO
        // customer is renamed. Storing the QBO customer id lets us filter by an
        // identifier that never changes; name-based filtering is kept as a
        // fallback for rows synced before this column existed.
        Schema::table('quickbooks_invoices', function (Blueprint $table) {
            $table->string('customer_qbo_id')->nullable()->index()->after('customer_name');
        });

        Schema::table('quickbooks_transactions', function (Blueprint $table) {
            $table->string('customer_qbo_id')->nullable()->index()->after('entity_name');
        });
    }

    public function down(): void
    {
        Schema::table('quickbooks_invoices', function (Blueprint $table) {
            $table->dropColumn('customer_qbo_id');
        });

        Schema::table('quickbooks_transactions', function (Blueprint $table) {
            $table->dropColumn('customer_qbo_id');
        });
    }
};
