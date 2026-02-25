<?php

namespace Database\Seeders;

use App\Models\Owner;
use Illuminate\Database\Seeder;

class SystemOwnerSeeder extends Seeder
{
    /**
     * Seed the SYSTEM owner.
     */
    public function run(): void
    {
        // Get the first super_admin user ID, or use null if none exists
        $createdBy = \App\Models\User::where('role', 'super_admin')->first()?->id;

        Owner::updateOrCreate(
            ['owner_code' => 'SYS-000'],
            [
                'owner_type' => 'SYSTEM',
                'name' => 'SYSTEM',
                'description' => 'Internal balancing account. Do not modify.',
                'status' => 'ACTIVE',
                'is_system' => true,
                'created_by' => $createdBy,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
