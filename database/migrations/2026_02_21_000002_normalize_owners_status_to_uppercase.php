<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Convert existing lowercase status values to uppercase
        DB::table('owners')
            ->where('status', 'active')
            ->update(['status' => 'ACTIVE']);
            
        DB::table('owners')
            ->where('status', 'inactive')
            ->update(['status' => 'INACTIVE']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Convert back to lowercase if needed
        DB::table('owners')
            ->where('status', 'ACTIVE')
            ->update(['status' => 'active']);
            
        DB::table('owners')
            ->where('status', 'INACTIVE')
            ->update(['status' => 'inactive']);
    }
};
