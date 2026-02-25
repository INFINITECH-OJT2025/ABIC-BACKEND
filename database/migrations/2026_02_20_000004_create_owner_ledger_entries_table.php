<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Precomputed ledger per owner - fast retrieval without joins.
     * One transaction = two ledger entries (from_owner debit, to_owner credit).
     * Consolidated migration: voucher_date is nullable (changed in 2026_02_20_000006)
     */
    public function up(): void
    {
        Schema::create('owner_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('owners')->onDelete('cascade');
            $table->foreignId('transaction_id')->constrained('transactions')->onDelete('cascade');

            $table->string('voucher_no', 50)->nullable(false);
            $table->date('voucher_date')->nullable(); // Made nullable in 2026_02_20_000006 to handle transactions without vouchers

            $table->string('instrument_no', 100)->nullable();

            $table->decimal('debit', 14, 2)->nullable(false)->default(0);
            $table->decimal('credit', 14, 2)->nullable(false)->default(0);

            $table->decimal('running_balance', 14, 2)->nullable(false);

            $table->foreignId('unit_id')->nullable()->constrained('units')->onDelete('set null');
            $table->text('particulars')->nullable();

            $table->string('transfer_group_id', 100)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['owner_id', 'voucher_date']);
            $table->index(['owner_id', 'transaction_id']);
            $table->index('voucher_date');
            $table->index('transfer_group_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('owner_ledger_entries');
    }
};
