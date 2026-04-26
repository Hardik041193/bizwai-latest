<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('email');
            $table->string('job_title', 100)->nullable()->after('phone');
            $table->string('address', 255)->nullable()->after('job_title');
            $table->text('bio')->nullable()->after('address');
            $table->string('avatar')->nullable()->after('bio');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'job_title', 'address', 'bio', 'avatar']);
        });
    }
};
