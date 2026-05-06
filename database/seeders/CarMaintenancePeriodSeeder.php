<?php

namespace Database\Seeders;

use App\Models\Car;
use App\Models\CarMaintenancePeriod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CarMaintenancePeriodSeeder extends Seeder
{
    public function run(): void
    {
        $periods = [
            [
                'plate_number' => '222-V-88',
                'start_date' => now()->addDays(4)->toDateString(),
                'end_date' => now()->addDays(6)->toDateString(),
                'reason' => 'Scheduled engine inspection',
                'status' => 'scheduled',
            ],
            [
                'plate_number' => '999-K-10',
                'start_date' => now()->addDays(18)->toDateString(),
                'end_date' => now()->addDays(20)->toDateString(),
                'reason' => 'Scheduled tire replacement',
                'status' => 'scheduled',
            ],
        ];

        foreach ($periods as $period) {
            $car = Car::where('plate_number', $period['plate_number'])->first();

            // Skip the maintenance row if the matching car seed is missing.
            if (!$car) {
                continue;
            }

            // Seed by car/date window so reruns update the same maintenance period.
            CarMaintenancePeriod::updateOrCreate(
                [
                    'car_id' => $car->id,
                    'start_date' => $period['start_date'],
                    'end_date' => $period['end_date'],
                ],
                [
                    'id' => CarMaintenancePeriod::where('car_id', $car->id)
                        ->where('start_date', $period['start_date'])
                        ->where('end_date', $period['end_date'])
                        ->value('id') ?? (string) Str::uuid(),
                    'reason' => $period['reason'],
                    'status' => $period['status'],
                ]
            );
        }
    }
}
