<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sales paid for on the spot, with no invoice.
     *
     * Revenue was counted from paid invoices only, so these sales were missing
     * from every revenue figure.
     */
    public function up(): void
    {
        Schema::create('quickbooks_sales_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('realm_id')->index();
            $table->string('qbo_id')->index();
            $table->string('doc_number')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_qbo_id')->nullable()->index();
            $table->string('customer_email')->nullable();
            $table->date('txn_date')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('total_tax', 15, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->string('deposit_account_name')->nullable();
            $table->string('currency_ref', 10)->nullable();
            $table->json('line_items')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['realm_id', 'qbo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quickbooks_sales_receipts');
    }
};
