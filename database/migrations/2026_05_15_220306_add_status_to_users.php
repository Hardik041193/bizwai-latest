<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->tinyInteger('status')->default(1)->comment('1 = Approve, 2 = Reject')->after('email_verified_at');
            $table->string('company_name', 255)->nullable()->after('status');
            $table->tinyInteger('qbo_status')->default(0)->comment('0 = Disconnected, 1 = Connected')->after('company_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('status','company_name','qbo_status');
        });
    }
};
