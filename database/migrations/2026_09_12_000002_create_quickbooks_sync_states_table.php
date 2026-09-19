<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-entity sync progress for a realm.
     *
     * Two consumers depend on this:
     *
     *  1. The frontend, which polls it for a real progress indicator. Before
     *     this existed the UI cleared its "syncing" flag as soon as the POST
     *     returned, which was only ever accurate because the sync ran inline.
     *  2. The AI tools, which need to know an entity is still importing so they
     *     can qualify an answer instead of reporting a confident zero.
     */
    public function up(): void
    {
        Schema::create('quickbooks_sync_states', function (Blueprint $table) {
            $table->id();
            $table->string('realm_id')->index();
            $table->string('entity', 50);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('records_synced')->default(0);

            // Reserved for the pagination cursor in R2. Null means "no paging
            // in progress"; the column exists now so the state row shape does
            // not change under the frontend later.
            $table->unsignedInteger('start_position')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['realm_id', 'entity']);
            $table->index(['realm_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quickbooks_sync_states');
    }
};
