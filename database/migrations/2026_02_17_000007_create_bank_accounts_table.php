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
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            
            // Who owns this account in the system ledger
            $table->foreignId('owner_id')->constrained('owners')->onDelete('restrict');
            
            // Bank institution (required only if account_type = BANK)
            $table->foreignId('bank_id')->nullable()->constrained('banks')->onDelete('restrict');
            
            // Internal display name in your system
            $table->string('account_name', 150)->nullable(false);
            
            // Real-world bank/wallet number (nullable for CASH / INTERNAL)
            $table->string('account_number', 100)->nullable();
            
            // Name printed on bank or wallet account
            $table->string('account_holder', 150)->nullable(false);
            
            // Container type: BANK | GCASH | CASH | INTERNAL
            $table->string('account_type', 20)->nullable(false);
            
            // Ledger start values
            $table->decimal('opening_balance', 14, 2)->nullable(false)->default(0);
            $table->date('opening_date')->nullable(false);
            
            // Currency support
            $table->string('currency', 10)->nullable(false)->default('PHP');
            
            // Lifecycle control: ACTIVE | INACTIVE | CLOSED
            $table->string('status', 20)->nullable(false)->default('ACTIVE');
            
            // Admin notes
            $table->text('notes')->nullable();
            
            // Audit
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            
            // Indexes
            $table->index('owner_id');
            $table->index('status');
            $table->index('account_type');
            // Unique constraint: bank_id + account_number (only when both are present)
            $table->unique(['bank_id', 'account_number'], 'bank_accounts_bank_id_account_number_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
