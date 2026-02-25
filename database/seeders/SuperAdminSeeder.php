<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    /**
     * Seed super_admin and super_admin_viewer users.
     */
    public function run(): void
    {
        // Simple password that meets requirements: symbol, caps, small letters, numbers
        $defaultPassword = 'Admin@123';

        // Super Admins
        $superAdmins = [
            [
                'email' => 'abicrealty.krissa@gmail.com',
                'name' => 'Krissa',
                'role' => 'super_admin',
            ],
            [
                'email' => 'infinitechcorp.ph@gmail.com',
                'name' => 'Infinitech Corp',
                'role' => 'super_admin',
            ],
        ];

        // Super Admin Viewers
        $superAdminViewers = [
            [
                'email' => 'abicrealty.rose@gmail.com',
                'name' => 'Rose',
                'role' => 'super_admin_viewer',
            ],
            [
                'email' => 'abicrealtyph@gmail.com',
                'name' => 'ABIC Realty',
                'role' => 'super_admin_viewer',
            ],
            [
                'email' => 'infinitechadv@gmail.com',
                'name' => 'Infinitech Adv',
                'role' => 'super_admin_viewer',
            ],
        ];

        // Seed Super Admins
        foreach ($superAdmins as $admin) {
            User::updateOrCreate(
                ['email' => $admin['email']],
                [
                    'name' => $admin['name'],
                    'password' => Hash::make($defaultPassword),
                    'role' => $admin['role'],
                    'account_status' => 'active',
                    'email_verified_at' => now(),
                    'password_expires_at' => null,
                    'is_password_expired' => false,
                    'last_password_change' => now(),
                ]
            );
        }

        // Seed Super Admin Viewers
        foreach ($superAdminViewers as $viewer) {
            User::updateOrCreate(
                ['email' => $viewer['email']],
                [
                    'name' => $viewer['name'],
                    'password' => Hash::make($defaultPassword),
                    'role' => $viewer['role'],
                    'account_status' => 'active',
                    'email_verified_at' => now(),
                    'password_expires_at' => null,
                    'is_password_expired' => false,
                    'last_password_change' => now(),
                ]
            );
        }
    }
}
