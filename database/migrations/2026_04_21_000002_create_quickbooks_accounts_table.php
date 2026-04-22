<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quickbooks_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('realm_id')->index();
            $table->string('qbo_id')->index();
            $table->string('name');
            $table->string('account_type')->nullable();
            $table->string('account_sub_type')->nullable();
            $table->string('classification')->nullable();
            $table->decimal('current_balance', 15, 2)->default(0);
            $table->string('currency_ref', 10)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['realm_id', 'qbo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quickbooks_accounts');
    }
};
