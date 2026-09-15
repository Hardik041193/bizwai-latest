<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Watermark for incremental (change data capture) sync.
     *
     * The time the entity's last successful run started. It is only written on
     * success, so a failed run leaves the previous watermark in place and the
     * next run asks QuickBooks for changes since then, instead of skipping
     * whatever the failed run missed.
     */
    public function up(): void
    {
        Schema::table('quickbooks_sync_states', function (Blueprint $table) {
            $table->timestamp('changes_through')->nullable()->after('last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('quickbooks_sync_states', function (Blueprint $table) {
            $table->dropColumn('changes_through');
        });
    }
};
