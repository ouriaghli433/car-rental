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
        Schema::create('agencies', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('owner_id')->constrained('users');
            $table->foreignUuid('city_id')->constrained('cities');

            $table->string('logo_url')->nullable();
            $table->string('name');
            $table->string('slug')->unique();
            
            
            $table->string('address');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->text('description')->nullable();

            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');

            $table->decimal('balance', 10, 2)->default(0);
            $table->float('avg_rating')->default(0);
            $table->integer('total_reviews')->default(0);
            $table->softDeletes();
            $table->timestamps();

            // Speed up owner lookups and public city/status agency listings.
            $table->index('owner_id');
            $table->index(['city_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agencies');
    }
};
