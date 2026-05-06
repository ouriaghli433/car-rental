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
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('reservation_id')->nullable()->constrained('reservations');
            $table->foreignUuid('agency_id')->constrained('agencies');

            $table->decimal('amount', 10, 2);

            $table->enum('type', ['commission', 'top_up', 'refund']);
            $table->enum('status', ['pending', 'completed', 'failed'])->default('completed');

            $table->decimal('balance_before', 10, 2);
            $table->decimal('balance_after', 10, 2);

            $table->string('reference')->nullable();

            $table->timestamps();

            // Speed up agency ledger screens and reservation payment lookups.
            $table->index(['agency_id', 'created_at']);
            $table->index(['reservation_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
