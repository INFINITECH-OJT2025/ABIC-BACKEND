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
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->nullable()->constrained('owners')->onDelete('cascade');
            // Use bigInteger instead of foreignId if properties table doesn't exist yet
            // Change to foreignId('property_id')->nullable()->constrained('properties')->onDelete('set null') 
            // once properties table is created
            $table->bigInteger('property_id')->nullable();
            $table->string('unit_name', 100)->nullable(false);
            $table->string('status', 20)->nullable(false)->default('ACTIVE');
            $table->text('notes')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('owner_id');
            $table->index('property_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
