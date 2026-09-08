<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quickbooks_tokens', function (Blueprint $table) {
            $table->string('selected_client_qbo_id')->nullable()->after('country');
            $table->string('selected_client_name')->nullable()->after('selected_client_qbo_id');
            $table->timestamp('client_selected_at')->nullable()->after('selected_client_name');
        });
    }

    public function down(): void
    {
        Schema::table('quickbooks_tokens', function (Blueprint $table) {
            $table->dropColumn(['selected_client_qbo_id', 'selected_client_name', 'client_selected_at']);
        });
    }
};
