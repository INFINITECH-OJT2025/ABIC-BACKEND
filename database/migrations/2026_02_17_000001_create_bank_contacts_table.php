<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Consolidated migration: combines create_bank_contacts_table, update_bank_contacts_table, 
     * and restructure_bank_contacts into a single migration.
     */
    public function up(): void
    {
        // Create bank_contacts table with final structure
        Schema::create('bank_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_id')->constrained('banks')->onDelete('cascade');
            $table->string('branch_name', 150)->nullable(false);
            $table->string('contact_person', 255)->nullable();
            $table->string('position', 150)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('bank_id');
            $table->index('branch_name');
        });

        // Create bank_contact_channels table (from restructure migration)
        Schema::create('bank_contact_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('bank_contacts')->onDelete('cascade');
            $table->string('channel_type', 30); // PHONE | MOBILE | EMAIL | VIBER
            $table->string('value', 255);
            $table->string('label', 100)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('contact_id');
            $table->index('channel_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_contact_channels');
        Schema::dropIfExists('bank_contacts');
    }
};
