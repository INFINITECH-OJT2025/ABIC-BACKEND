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
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            
            // Activity classification
            $table->string('activity_type', 255); // e.g., 'TRANSACTION', 'OWNER', 'UNIT', etc.
            $table->string('action', 255); // e.g., 'CREATE', 'UPDATE', 'DELETE', etc.
            $table->string('status', 255); // e.g., 'SUCCESS', 'FAILED', 'PENDING', etc.
            
            // Activity details
            $table->string('title', 255);
            $table->text('description');
            
            // User information
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('user_name', 255)->nullable();
            $table->string('user_email', 255)->nullable();
            
            // Target information (polymorphic)
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_type', 255)->nullable(); // e.g., 'App\Models\Transaction', 'App\Models\Owner', etc.
            
            // Additional metadata
            $table->json('metadata')->nullable();
            
            // Request information
            $table->string('ip_address', 255)->nullable();
            $table->text('user_agent')->nullable();
            
            // Timestamps
            $table->timestamp('created_at')->nullable();
            
            // Indexes for performance
            $table->index('activity_type');
            $table->index('user_id');
            $table->index('target_type');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
