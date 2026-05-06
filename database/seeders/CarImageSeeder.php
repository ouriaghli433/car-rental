<?php

namespace Database\Seeders;

use App\Models\Car;
use App\Models\CarImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CarImageSeeder extends Seeder
{
    public function run(): void
    {
        $cars = Car::all();

        foreach ($cars as $car) {
            $images = [
                ['image_url' => 'https://picsum.photos/seed/' . $car->plate_number . '-primary/600/400', 'is_primary' => true],
                ['image_url' => 'https://picsum.photos/seed/' . $car->plate_number . '-side/600/400', 'is_primary' => false],
                ['image_url' => 'https://picsum.photos/seed/' . $car->plate_number . '-inside/600/400', 'is_primary' => false],
            ];

            foreach ($images as $image) {
                // Seed by car/image URL so reruns do not duplicate gallery rows.
                CarImage::updateOrCreate(
                    ['car_id' => $car->id, 'image_url' => $image['image_url']],
                    [
                        'id' => CarImage::where('car_id', $car->id)
                            ->where('image_url', $image['image_url'])
                            ->value('id') ?? (string) Str::uuid(),
                        'is_primary' => $image['is_primary'],
                    ]
                );
            }
        }
    }
}
