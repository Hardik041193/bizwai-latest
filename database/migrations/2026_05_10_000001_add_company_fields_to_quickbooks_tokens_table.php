<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quickbooks_tokens', function (Blueprint $table) {
            $table->string('company_name')->nullable()->after('realm_id');
            $table->string('legal_name')->nullable()->after('company_name');
            $table->string('company_email')->nullable()->after('legal_name');
            $table->string('country', 10)->nullable()->after('company_email');
        });
    }

    public function down(): void
    {
        Schema::table('quickbooks_tokens', function (Blueprint $table) {
            $table->dropColumn(['company_name', 'legal_name', 'company_email', 'country']);
        });
    }
};
