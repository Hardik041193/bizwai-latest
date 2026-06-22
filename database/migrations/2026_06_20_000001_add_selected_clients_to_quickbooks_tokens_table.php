<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quickbooks_tokens', function (Blueprint $table) {
            // Array of {qbo_id, name} for the clients the user chose to track.
            // An empty array together with a non-null client_selected_at means
            // "All clients" (no filtering). Null client_selected_at means the
            // user has not completed the selection step yet.
            $table->json('selected_clients')->nullable()->after('selected_client_name');
        });

        // Backfill existing single-client selections into the new list so already
        // connected accounts keep working without re-selecting.
        DB::table('quickbooks_tokens')
            ->whereNotNull('selected_client_qbo_id')
            ->orderBy('id')
            ->each(function ($row) {
                DB::table('quickbooks_tokens')
                    ->where('id', $row->id)
                    ->update([
                        'selected_clients' => json_encode([[
                            'qbo_id' => $row->selected_client_qbo_id,
                            'name'   => $row->selected_client_name,
                        ]]),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('quickbooks_tokens', function (Blueprint $table) {
            $table->dropColumn('selected_clients');
        });
    }
};
