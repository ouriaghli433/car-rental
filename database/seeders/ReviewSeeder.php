<?php

namespace Database\Seeders;

use App\Models\Reservation;
use App\Models\Review;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ReviewSeeder extends Seeder
{
    public function run(): void
    {
        $completedReservations = Reservation::where('status', 'completed')->get();

        foreach ($completedReservations as $reservation) {
            // Add one review per completed reservation to match the unique reservation review rule.
            Review::updateOrCreate(
                ['reservation_id' => $reservation->id],
                [
                    'id' => Review::where('reservation_id', $reservation->id)->value('id') ?? (string) Str::uuid(),
                    'car_rating' => 5,
                    'agency_rating' => 4,
                    'comment' => 'Seeded review for completed reservation testing.',
                ]
            );
        }
    }
}
