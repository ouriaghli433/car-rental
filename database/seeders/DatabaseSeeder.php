<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();
        $this->call([
            UserSeeder::class,
            CitySeeder::class,
            AgencySeeder::class,
            CarSeeder::class,
            // Seed maintenance windows before reservations so date availability can be tested.
            CarMaintenancePeriodSeeder::class,
            ReservationSeeder::class,
            PaymentSeeder::class,
            CarImageSeeder::class,
            // Seed reviews after reservations so completed bookings can receive ratings.
            ReviewSeeder::class,
        ]);
    }
}
