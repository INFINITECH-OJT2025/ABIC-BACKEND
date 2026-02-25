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
        Schema::table('transactions', function (Blueprint $table) {
            // Check if column exists before dropping
            if (Schema::hasColumn('transactions', 'transaction_category')) {
                // Try to drop index if it exists (ignore error if it doesn't)
                try {
                    $table->dropIndex(['transaction_category']);
                } catch (\Exception $e) {
                    // Index doesn't exist, continue
                }
                
                // Drop the column
                $table->dropColumn('transaction_category');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('transaction_category', 30)->nullable()->after('trans_method');
            $table->index('transaction_category');
        });
    }
};
