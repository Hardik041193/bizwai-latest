<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The vendor side of the ledger and money moving through the bank.
     *
     * Bills were already synced, but not who the vendors are, what was paid
     * against those bills, or credits vendors issued, so nothing could say what
     * a vendor had been paid. Refunds to customers, bank deposits and transfers
     * between accounts were missing in the same way.
     */
    public function up(): void
    {
        $common = function (Blueprint $table) {
            $table->id();
            $table->string('realm_id')->index();
            $table->string('qbo_id')->index();
        };

        $closing = function (Blueprint $table) {
            $table->string('currency_ref', 10)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['realm_id', 'qbo_id']);
        };

        Schema::create('quickbooks_vendors', function (Blueprint $table) use ($common, $closing) {
            $common($table);
            $table->string('display_name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            // What the company currently owes this vendor.
            $table->decimal('balance', 15, 2)->default(0);
            $table->boolean('active')->default(true);
            $closing($table);
        });

        Schema::create('quickbooks_bill_payments', function (Blueprint $table) use ($common, $closing) {
            $common($table);
            $table->string('doc_number')->nullable();
            $table->string('vendor_name')->nullable();
            $table->string('vendor_qbo_id')->nullable()->index();
            $table->date('txn_date')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            // Check or CreditCard, and the bank or card account it was paid from.
            $table->string('pay_type')->nullable();
            $table->string('account_name')->nullable();
            $table->json('bill_qbo_ids')->nullable();
            $closing($table);
        });

        Schema::create('quickbooks_vendor_credits', function (Blueprint $table) use ($common, $closing) {
            $common($table);
            $table->string('doc_number')->nullable();
            $table->string('vendor_name')->nullable();
            $table->string('vendor_qbo_id')->nullable()->index();
            $table->date('txn_date')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            // Credit not yet used against a bill.
            $table->decimal('balance', 15, 2)->default(0);
            $closing($table);
        });

        Schema::create('quickbooks_refund_receipts', function (Blueprint $table) use ($common, $closing) {
            $common($table);
            $table->string('doc_number')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_qbo_id')->nullable()->index();
            $table->date('txn_date')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('payment_method')->nullable();
            // The account the refund was paid out of.
            $table->string('account_name')->nullable();
            $table->json('line_items')->nullable();
            $closing($table);
        });

        Schema::create('quickbooks_deposits', function (Blueprint $table) use ($common, $closing) {
            $common($table);
            $table->date('txn_date')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('account_name')->nullable();
            $table->string('account_qbo_id')->nullable();
            $table->unsignedInteger('line_count')->default(0);
            $table->text('description')->nullable();
            $closing($table);
        });

        Schema::create('quickbooks_transfers', function (Blueprint $table) use ($common, $closing) {
            $common($table);
            $table->date('txn_date')->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('from_account_name')->nullable();
            $table->string('to_account_name')->nullable();
            $table->text('description')->nullable();
            $closing($table);
        });
    }

    public function down(): void
    {
        foreach (['transfers', 'deposits', 'refund_receipts', 'vendor_credits', 'bill_payments', 'vendors'] as $table) {
            Schema::dropIfExists("quickbooks_{$table}");
        }
    }
};
