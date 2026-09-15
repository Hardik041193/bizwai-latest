<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customer payments, and which invoices each one settled.
     *
     * An invoice's balance says whether it is paid but not when, how, or by
     * which payment. The linked invoice ids answer those.
     */
    public function up(): void
    {
        Schema::create('quickbooks_payments', function (Blueprint $table) {
            $table->id();
            $table->string('realm_id')->index();
            $table->string('qbo_id')->index();
            $table->string('customer_name')->nullable();
            $table->string('customer_qbo_id')->nullable()->index();
            $table->date('txn_date')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('unapplied_amount', 15, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->string('payment_ref_number')->nullable();
            $table->string('deposit_account_qbo_id')->nullable();
            $table->string('deposit_account_name')->nullable();
            $table->json('invoice_qbo_ids')->nullable();
            $table->string('currency_ref', 10)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['realm_id', 'qbo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quickbooks_payments');
    }
};
