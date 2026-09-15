<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Credits issued to customers.
     *
     * Credits reduce revenue. Without them, refunds and adjustments never came
     * off any revenue figure.
     */
    public function up(): void
    {
        Schema::create('quickbooks_credit_memos', function (Blueprint $table) {
            $table->id();
            $table->string('realm_id')->index();
            $table->string('qbo_id')->index();
            $table->string('doc_number')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_qbo_id')->nullable()->index();
            $table->date('txn_date')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('remaining_credit', 15, 2)->default(0);
            $table->decimal('total_tax', 15, 2)->default(0);
            $table->string('currency_ref', 10)->nullable();
            $table->json('line_items')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['realm_id', 'qbo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quickbooks_credit_memos');
    }
};
