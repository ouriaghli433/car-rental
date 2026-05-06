<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\City;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AgencySeeder extends Seeder
{
    public function run(): void
    {
        $agencies = [
            [
                'owner_email' => 'agency@test.com',
                'city_name' => 'Tangier',
                'name' => 'Premium Cars',
                'slug' => 'premium-cars',
                'description' => 'Approved agency with enough balance for confirmation tests.',
                'address' => 'Tangier Center',
                'phone' => '0600000100',
                'email' => 'premium@test.com',
                'status' => 'approved',
                'balance' => 1000,
            ],
            [
                'owner_email' => 'agency2@test.com',
                'city_name' => 'Casablanca',
                'name' => 'Casa Drive',
                'slug' => 'casa-drive',
                'description' => 'Approved agency with lower balance for payment edge cases.',
                'address' => 'Maarif, Casablanca',
                'phone' => '0600000200',
                'email' => 'casa@test.com',
                'status' => 'approved',
                'balance' => 75,
            ],
            [
                'owner_email' => 'pending-owner@test.com',
                'city_name' => 'Rabat',
                'name' => 'Rabat Pending Rentals',
                'slug' => 'rabat-pending-rentals',
                'description' => 'Pending agency for admin approval screens.',
                'address' => 'Agdal, Rabat',
                'phone' => '0600000300',
                'email' => 'pending-agency@test.com',
                'status' => 'pending',
                'balance' => 0,
            ],
        ];

        foreach ($agencies as $agency) {
            $owner = User::where('email', $agency['owner_email'])->first();
            $city = City::where('name', $agency['city_name'])->first();

            // Skip the agency row if the required owner or city seed is missing.
            if (!$owner || !$city) {
                continue;
            }

            // Seed by slug because slugs are unique public identifiers for agencies.
            Agency::updateOrCreate(
                ['slug' => $agency['slug']],
                [
                    'id' => Agency::where('slug', $agency['slug'])->value('id') ?? (string) Str::uuid(),
                    'owner_id' => $owner->id,
                    'city_id' => $city->id,
                    'name' => $agency['name'],
                    'description' => $agency['description'],
                    'address' => $agency['address'],
                    'phone' => $agency['phone'],
                    'email' => $agency['email'],
                    'status' => $agency['status'],
                    'balance' => $agency['balance'],
                ]
            );
        }
    }
}
