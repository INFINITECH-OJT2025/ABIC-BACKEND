<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds upload_status for async Firebase uploads - allows transaction to succeed
     * immediately while images upload in background.
     */
    public function up(): void
    {
        Schema::table('transaction_attachments', function (Blueprint $table) {
            $table->string('upload_status', 20)->default('completed')->after('file_path');
            $table->string('temp_path', 500)->nullable()->after('upload_status');
        });

        // Allow file_path to be null when upload is pending
        Schema::table('transaction_attachments', function (Blueprint $table) {
            $table->text('file_path')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transaction_attachments', function (Blueprint $table) {
            $table->dropColumn(['upload_status', 'temp_path']);
            $table->text('file_path')->nullable(false)->change();
        });
    }
};
