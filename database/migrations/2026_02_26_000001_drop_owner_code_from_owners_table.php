<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    /**
     * Run the migrations.
     * Remove owner_code column - no longer needed.
     */
    public function up(): void
    {
        Schema::table('owners', function (Blueprint $table) {
            if (Schema::hasColumn('owners', 'owner_code')) {
                $table->dropColumn('owner_code');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('owners', function (Blueprint $table) {
            $table->string('owner_code', 30)->nullable()->after('id');
            $table->index('owner_code');
        });
    }
};
