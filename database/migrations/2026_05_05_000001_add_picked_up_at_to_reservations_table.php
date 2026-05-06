<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Adds a picked_up_at timestamp so clients can confirm they have physically
// collected the car. This marks the official start of the rental trip.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            // Nullable because most reservations haven't been picked up yet.
            // Set by the client via POST /reservations/{id}/pickup.
            $table->timestamp('picked_up_at')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('picked_up_at');
        });
    }
};
