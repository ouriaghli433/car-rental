<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Car;
use App\Models\City;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CarSeeder extends Seeder
{
    public function run(): void
    {
        $cars = [
            [
                'agency_slug' => 'premium-cars',
                'city_name' => 'Tangier',
                'brand' => 'Toyota',
                'model' => 'Yaris',
                'year' => 2022,
                'color' => 'white',
                'plate_number' => '123-A-45',
                'type' => 'sedan',
                'transmission' => 'manual',
                'seats' => 5,
                'price_per_day' => 200,
                'status' => 'available',
            ],
            [
                'agency_slug' => 'premium-cars',
                'city_name' => 'Tangier',
                'brand' => 'Dacia',
                'model' => 'Duster',
                'year' => 2023,
                'color' => 'gray',
                'plate_number' => '789-C-89',
                'type' => 'suv',
                'transmission' => 'manual',
                'seats' => 5,
                'price_per_day' => 300,
                'status' => 'available',
            ],
            [
                'agency_slug' => 'premium-cars',
                'city_name' => 'Rabat',
                'brand' => 'Renault',
                'model' => 'Trafic',
                'year' => 2020,
                'color' => 'silver',
                'plate_number' => '222-V-88',
                'type' => 'van',
                'transmission' => 'manual',
                'seats' => 9,
                'price_per_day' => 450,
                'status' => 'maintenance',
            ],
            [
                'agency_slug' => 'casa-drive',
                'city_name' => 'Casablanca',
                'brand' => 'Hyundai',
                'model' => 'i10',
                'year' => 2021,
                'color' => 'black',
                'plate_number' => '456-B-67',
                'type' => 'sedan',
                'transmission' => 'automatic',
                'seats' => 5,
                'price_per_day' => 180,
                'status' => 'available',
            ],
            [
                'agency_slug' => 'casa-drive',
                'city_name' => 'Casablanca',
                'brand' => 'Kia',
                'model' => 'Sportage',
                'year' => 2024,
                'color' => 'blue',
                'plate_number' => '999-K-10',
                'type' => 'suv',
                'transmission' => 'automatic',
                'seats' => 5,
                'price_per_day' => 380,
                'status' => 'rented',
            ],
        ];

        foreach ($cars as $car) {
            $agency = Agency::where('slug', $car['agency_slug'])->first();
            $city = City::where('name', $car['city_name'])->first();

            // Skip the car row if the required agency or city seed is missing.
            if (!$agency || !$city) {
                continue;
            }

            // Seed by plate number because each plate represents one physical car.
            Car::updateOrCreate(
                ['plate_number' => $car['plate_number']],
                [
                    'id' => Car::where('plate_number', $car['plate_number'])->value('id') ?? (string) Str::uuid(),
                    'agency_id' => $agency->id,
                    'city_id' => $city->id,
                    'brand' => $car['brand'],
                    'model' => $car['model'],
                    'year' => $car['year'],
                    'color' => $car['color'],
                    'type' => $car['type'],
                    'transmission' => $car['transmission'],
                    'seats' => $car['seats'],
                    'price_per_day' => $car['price_per_day'],
                    'description' => $car['brand'] . ' ' . $car['model'] . ' test car',
                    'status' => $car['status'],
                ]
            );
        }
    }
}
