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
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('paid_to')->nullable();
            $table->string('voucher_no');
            $table->date('date')->nullable();
            $table->string('project_details')->nullable();
            $table->string('owner_client')->nullable();
            $table->string('purpose')->nullable();
            $table->string('note')->nullable();
            $table->string('total_amount');
            $table->string('received_by_name')->nullable();
            $table->string('received_by_signature_url')->nullable();
            $table->string('received_by_date')->nullable();
            $table->string('approved_by_name')->nullable();
            $table->string('approved_by_signature_url')->nullable();
            $table->string('approved_by_date')->nullable();
            $table->enum('status', ['approved', 'cancelled'])->default('approved');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
