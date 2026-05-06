<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            // Stores the agency owner's requested profile changes as JSON.
            // The current approved values stay live until an admin approves the update.
            // Null means no pending update is waiting for review.
            $table->json('pending_changes')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('pending_changes');
        });
    }
};
