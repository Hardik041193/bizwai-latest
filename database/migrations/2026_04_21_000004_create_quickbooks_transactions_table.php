<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quickbooks_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('realm_id')->index();
            $table->string('qbo_id')->index();
            $table->string('txn_type')->nullable();
            $table->date('txn_date')->nullable();
            $table->string('account_name')->nullable();
            $table->string('entity_name')->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->text('description')->nullable();
            $table->string('currency_ref', 10)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['realm_id', 'qbo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quickbooks_transactions');
    }
};
