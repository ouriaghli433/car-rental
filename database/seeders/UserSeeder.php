<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'first_name' => 'Admin',
                'last_name' => 'System',
                'email' => 'admin@test.com',
                'phone' => '0600000000',
                'role' => 'admin',
                'status' => 'active',
            ],
            [
                'first_name' => 'Ahmed',
                'last_name' => 'Client',
                'email' => 'client@test.com',
                'phone' => '0600000001',
                'role' => 'client',
                'status' => 'active',
            ],
            [
                'first_name' => 'Sara',
                'last_name' => 'Client',
                'email' => 'sara@test.com',
                'phone' => '0600000002',
                'role' => 'client',
                'status' => 'active',
            ],
            [
                'first_name' => 'Blocked',
                'last_name' => 'Client',
                'email' => 'blocked@test.com',
                'phone' => '0600000003',
                'role' => 'client',
                'status' => 'active',
                'cancel_count_today' => 0,
                'blocked_until' => now()->addHours(12),
            ],
            [
                'first_name' => 'Yassine',
                'last_name' => 'Agency',
                'email' => 'agency@test.com',
                'phone' => '0600000004',
                'role' => 'agency_owner',
                'status' => 'active',
            ],
            [
                'first_name' => 'Meryem',
                'last_name' => 'Agency',
                'email' => 'agency2@test.com',
                'phone' => '0600000005',
                'role' => 'agency_owner',
                'status' => 'active',
            ],
            [
                'first_name' => 'Pending',
                'last_name' => 'Owner',
                'email' => 'pending-owner@test.com',
                'phone' => '0600000006',
                'role' => 'agency_owner',
                'status' => 'pending',
            ],
        ];

        foreach ($users as $user) {
            // Seed by email so the command can be safely rerun without duplicate users.
            User::updateOrCreate(
                ['email' => $user['email']],
                array_merge($user, [
                    'id' => User::where('email', $user['email'])->value('id') ?? (string) Str::uuid(),
                    'password' => bcrypt('123456'),
                ])
            );
        }
    }
}
