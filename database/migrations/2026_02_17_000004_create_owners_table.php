<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Consolidated migration: includes all columns from subsequent migrations
     */
    public function up(): void
    {
        Schema::create('owners', function (Blueprint $table) {
            $table->id();
            $table->string('owner_code', 30)->unique(); // Added in 2026_02_21_000003
            $table->string('owner_type', 30)->nullable(false);
            $table->string('name', 255)->nullable(false);
            $table->text('description')->nullable(); // Added in 2026_02_21_000003
            $table->string('email', 255)->nullable();
            $table->string('phone', 100)->nullable();
            $table->text('address')->nullable();
            $table->string('status', 20)->nullable(false)->default('ACTIVE');
            $table->boolean('is_system')->default(false); // Added in 2026_02_21_000001
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null'); // Added in 2026_02_21_000001
            $table->timestamps();

            // Indexes
            $table->index('owner_code');
            $table->index('owner_type');
            $table->index('status');
            $table->index('name');
            $table->index('is_system');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('owners');
    }
};
