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
        Schema::create('cars', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('agency_id')->constrained('agencies');
            $table->foreignUuid('city_id')->constrained('cities');

            $table->string('brand');
            $table->string('model');
            $table->integer('year');
            $table->string('color');
            // Plate numbers should be unique so the same physical car is not duplicated.
            $table->string('plate_number')->unique();

            $table->enum('type', ['sedan', 'suv', 'van']);
            $table->enum('transmission', ['automatic', 'manual']);

            $table->integer('seats');
            $table->decimal('price_per_day', 10, 2);

            $table->text('description')->nullable();

            $table->enum('status', ['available', 'rented', 'maintenance'])->default('available');

            $table->float('avg_rating')->default(0);
            $table->integer('total_reviews')->default(0);

            $table->softDeletes();
            $table->timestamps();

            // Speed up car search by agency, city, and availability.
            $table->index(['agency_id', 'status']);
            $table->index(['city_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cars');
    }
};
