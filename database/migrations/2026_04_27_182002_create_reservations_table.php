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
        Schema::create('reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('client_id')->constrained('users');
            $table->foreignUuid('car_id')->constrained('cars');
            $table->foreignUuid('agency_id')->constrained('agencies');

            $table->date('start_date');
            $table->date('end_date');

            $table->decimal('price_per_day_snapshot', 10, 2);
            $table->decimal('total_amount', 10, 2);

            $table->enum('status', [
                'pending',
                'confirmed',
                'cancelled',
                'completed'
            ])->default('pending');

            $table->text('cancellation_reason')->nullable();

            $table->enum('cancelled_by', [
                'client',
                'agency'
            ])->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // Speed up availability checks for active reservations on a car.
            $table->index(['car_id', 'status', 'start_date', 'end_date']);

            // Speed up reservation lists for clients and agency owners.
            $table->index(['client_id', 'status']);
            $table->index(['agency_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
