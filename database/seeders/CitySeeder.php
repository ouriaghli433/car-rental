<?php

namespace Database\Seeders;

use App\Models\City;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CitySeeder extends Seeder
{
    public function run(): void
    {
        $cities = [
            ['name' => 'Tangier', 'region' => 'Tanger-Tetouan-Al Hoceima', 'country' => 'Morocco', 'is_active' => true],
            ['name' => 'Casablanca', 'region' => 'Casablanca-Settat', 'country' => 'Morocco', 'is_active' => true],
            ['name' => 'Rabat', 'region' => 'Rabat-Sale-Kenitra', 'country' => 'Morocco', 'is_active' => true],
            ['name' => 'Marrakech', 'region' => 'Marrakech-Safi', 'country' => 'Morocco', 'is_active' => true],
            ['name' => 'Fes', 'region' => 'Fes-Meknes', 'country' => 'Morocco', 'is_active' => false],
        ];

        foreach ($cities as $city) {
            // Seed by city/country so repeat seeding updates the catalog instead of duplicating it.
            City::updateOrCreate(
                ['name' => $city['name'], 'country' => $city['country']],
                array_merge($city, [
                    'id' => City::where('name', $city['name'])->where('country', $city['country'])->value('id') ?? (string) Str::uuid(),
                ])
            );
        }
    }
}
