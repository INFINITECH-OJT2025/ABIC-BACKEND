<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Consolidated migration: combines create_transaction_attachments_table 
     * and increase_file_path_length_for_firebase_urls into a single migration.
     * Uses TEXT for file_path from the start to accommodate Firebase Storage URLs.
     */
    public function up(): void
    {
        Schema::create('transaction_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('transactions')->onDelete('cascade');
            $table->string('file_name', 255)->nullable(false);
            $table->string('file_type', 100)->nullable();
            $table->text('file_path')->nullable(false); // TEXT instead of VARCHAR(500) for Firebase URLs
            $table->timestamps();

            $table->index('transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_attachments');
    }
};
