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
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->nullable(false);
            $table->string('property_type', 30)->nullable(false); // CONDOMINIUM | HOUSE | LOT | COMMERCIAL
            $table->text('address')->nullable();
            $table->string('status', 20)->nullable(false)->default('ACTIVE'); // ACTIVE | INACTIVE
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
