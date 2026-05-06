<?php

namespace Database\Seeders;

use App\Models\Car;
use App\Models\Reservation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ReservationSeeder extends Seeder
{
    public function run(): void
    {
        $reservations = [
            [
                'reference' => 'SEED-PENDING-YARIS',
                'client_email' => 'client@test.com',
                'plate_number' => '123-A-45',
                'start_date' => now()->addDays(3)->toDateString(),
                'end_date' => now()->addDays(5)->toDateString(),
                'status' => 'pending',
                'expires_at' => now()->addHour(),
            ],
            [
                'reference' => 'SEED-CONFIRMED-I10',
                'client_email' => 'sara@test.com',
                'plate_number' => '456-B-67',
                'start_date' => now()->addDays(8)->toDateString(),
                'end_date' => now()->addDays(11)->toDateString(),
                'status' => 'confirmed',
                'confirmed_at' => now()->subHour(),
            ],
            [
                'reference' => 'SEED-CANCELLED-DUSTER',
                'client_email' => 'client@test.com',
                'plate_number' => '789-C-89',
                'start_date' => now()->addDays(14)->toDateString(),
                'end_date' => now()->addDays(16)->toDateString(),
                'status' => 'cancelled',
                'cancelled_at' => now()->subMinutes(30),
                'cancelled_by' => 'client',
                'cancellation_reason' => 'Seeded cancellation for refund testing.',
            ],
            [
                'reference' => 'SEED-COMPLETED-SPORTAGE',
                'client_email' => 'sara@test.com',
                'plate_number' => '999-K-10',
                'start_date' => now()->subDays(10)->toDateString(),
                'end_date' => now()->subDays(7)->toDateString(),
                'status' => 'completed',
                'confirmed_at' => now()->subDays(12),
                'completed_at' => now()->subDays(7),
            ],
        ];

        foreach ($reservations as $reservation) {
            $client = User::where('email', $reservation['client_email'])->first();
            $car = Car::with('agency')->where('plate_number', $reservation['plate_number'])->first();

            // Skip the reservation row if the required client, car, or agency seed is missing.
            if (!$client || !$car || !$car->agency) {
                continue;
            }

            // Calculate totals from each car so seeded reservations match the real pricing rules.
            $days = max(1, Carbon::parse($reservation['start_date'])->diffInDays(Carbon::parse($reservation['end_date'])));
            $total = $car->price_per_day * $days;

            // Seed by client/car/start date so reruns update the same booking scenario.
            Reservation::updateOrCreate(
                [
                    'client_id' => $client->id,
                    'car_id' => $car->id,
                    'start_date' => $reservation['start_date'],
                ],
                [
                    'id' => Reservation::where('client_id', $client->id)
                        ->where('car_id', $car->id)
                        ->where('start_date', $reservation['start_date'])
                        ->value('id') ?? (string) Str::uuid(),
                    'client_id' => $client->id,
                    'car_id' => $car->id,
                    'agency_id' => $car->agency->id,
                    'start_date' => $reservation['start_date'],
                    'end_date' => $reservation['end_date'],
                    'price_per_day_snapshot' => $car->price_per_day,
                    'total_amount' => $total,
                    'status' => $reservation['status'],
                    'confirmed_at' => $reservation['confirmed_at'] ?? null,
                    'expires_at' => $reservation['expires_at'] ?? null,
                    'cancelled_at' => $reservation['cancelled_at'] ?? null,
                    'cancelled_by' => $reservation['cancelled_by'] ?? null,
                    'completed_at' => $reservation['completed_at'] ?? null,
                    'cancellation_reason' => $reservation['cancellation_reason'] ?? null,
                ]
            );
        }
    }
}
