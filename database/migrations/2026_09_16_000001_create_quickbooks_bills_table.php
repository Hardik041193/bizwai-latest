<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vendor bills: money owed to suppliers, whether or not it has been paid.
     *
     * Only cash purchases were synced before, so spending on account was
     * invisible until the bill was paid, and expense figures ran low.
     */
    public function up(): void
    {
        Schema::create('quickbooks_bills', function (Blueprint $table) {
            $table->id();
            $table->string('realm_id')->index();
            $table->string('qbo_id')->index();
            $table->string('doc_number')->nullable();
            $table->string('vendor_name')->nullable();
            $table->string('vendor_qbo_id')->nullable()->index();
            $table->date('txn_date')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('balance', 15, 2)->default(0);
            $table->string('status')->nullable();
            $table->string('ap_account_name')->nullable();
            $table->text('description')->nullable();
            $table->string('currency_ref', 10)->nullable();
            $table->json('line_items')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['realm_id', 'qbo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quickbooks_bills');
    }
};
