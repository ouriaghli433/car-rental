<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Car;
use App\Models\CarImage;
use App\Models\City;
use App\Models\Reservation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

// Tests for: GET /cars, GET /cars/{id}, POST /cars, PUT /cars/{id},
//            DELETE /cars/{id}, POST /cars/{id}/images, DELETE /cars/{id}/images/{imageId},
//            POST /cars/{id}/maintenance, DELETE /cars/{id}/maintenance/{periodId}
class CarTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------------------------

    private function makeCity(): City
    {
        return City::create([
            'id' => Str::uuid(), 'name' => 'Tangier',
            'region' => 'TTA', 'country' => 'Morocco', 'is_active' => true,
        ]);
    }

    private function makeUser(string $role = 'client'): User
    {
        return User::create([
            'id' => Str::uuid(), 'first_name' => 'Test', 'last_name' => 'User',
            'email' => Str::random(6) . '@test.com',
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'active',
        ]);
    }

    private function makeAgency(User $owner, City $city, string $status = 'approved'): Agency
    {
        return Agency::create([
            'id' => Str::uuid(), 'owner_id' => $owner->id, 'city_id' => $city->id,
            'name' => 'Agency ' . Str::random(4), 'slug' => Str::random(8),
            'address' => '1 Main St', 'phone' => '0600000000',
            'email' => Str::random(6) . '@agency.com',
            'status' => $status, 'balance' => 1000,
        ]);
    }

    private function makeCar(Agency $agency, City $city): Car
    {
        return Car::create([
            'id' => Str::uuid(), 'agency_id' => $agency->id, 'city_id' => $city->id,
            'brand' => 'Toyota', 'model' => 'Corolla', 'year' => 2022,
            'color' => 'White', 'plate_number' => strtoupper(Str::random(8)),
            'type' => 'sedan', 'transmission' => 'automatic',
            'seats' => 5, 'price_per_day' => 200, 'status' => 'available',
        ]);
    }

    // -------------------------------------------------------------------------
    // PUBLIC LISTING
    // -------------------------------------------------------------------------

    public function test_anyone_can_list_cars(): void
    {
        $city  = $this->makeCity();
        $owner = $this->makeUser('agency_owner');
        $agency = $this->makeAgency($owner, $city);
        $this->makeCar($agency, $city);

        $this->getJson('/api/cars')->assertStatus(200);
    }

    public function test_cars_filtered_by_city(): void
    {
        $city1 = $this->makeCity();
        $city2 = City::create([
            'id' => Str::uuid(), 'name' => 'Casablanca',
            'region' => 'Grand Casa', 'country' => 'Morocco', 'is_active' => true,
        ]);

        $owner1 = $this->makeUser('agency_owner');
        $owner2 = $this->makeUser('agency_owner');
        $agency1 = $this->makeAgency($owner1, $city1);
        $agency2 = $this->makeAgency($owner2, $city2);

        $this->makeCar($agency1, $city1);
        $this->makeCar($agency2, $city2);

        $response = $this->getJson("/api/cars?city_id={$city1->id}");

        $response->assertStatus(200);
        // Only the car in city1 should appear.
        $this->assertCount(1, $response->json('data'));
    }

    public function test_availability_filter_excludes_reserved_cars(): void
    {
        $city   = $this->makeCity();
        $owner  = $this->makeUser('agency_owner');
        $agency = $this->makeAgency($owner, $city);
        $car    = $this->makeCar($agency, $city);
        $client = $this->makeUser('client');

        // Create an active reservation overlapping the search window.
        Reservation::create([
            'id' => Str::uuid(), 'client_id' => $client->id,
            'car_id' => $car->id, 'agency_id' => $agency->id,
            'start_date' => '2027-06-01', 'end_date' => '2027-06-10',
            'price_per_day_snapshot' => 200, 'total_amount' => 1800,
            'status' => 'confirmed',
        ]);

        // Searching for those exact dates should return 0 cars.
        $response = $this->getJson('/api/cars?start_date=2027-06-01&end_date=2027-06-10');
        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    public function test_anyone_can_view_a_single_car(): void
    {
        $city   = $this->makeCity();
        $owner  = $this->makeUser('agency_owner');
        $agency = $this->makeAgency($owner, $city);
        $car    = $this->makeCar($agency, $city);

        $this->getJson("/api/cars/{$car->id}")
             ->assertStatus(200)
             ->assertJsonPath('data.id', (string) $car->id);
    }

    // -------------------------------------------------------------------------
    // CREATE CAR
    // -------------------------------------------------------------------------

    public function test_agency_owner_can_create_car(): void
    {
        $city   = $this->makeCity();
        $owner  = $this->makeUser('agency_owner');
        $this->makeAgency($owner, $city); // owner now has an approved agency

        $response = $this->actingAs($owner)->postJson('/api/cars', [
            'brand'         => 'Honda',
            'model'         => 'Civic',
            'year'          => 2023,
            'color'         => 'Blue',
            'plate_number'  => 'TEST-001',
            'type'          => 'sedan',
            'transmission'  => 'automatic',
            'seats'         => 5,
            'price_per_day' => 300,
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.brand', 'Honda');

        $this->assertDatabaseHas('cars', ['plate_number' => 'TEST-001']);
    }

    public function test_client_cannot_create_car(): void
    {
        $client = $this->makeUser('client');

        $this->actingAs($client)->postJson('/api/cars', [
            'brand' => 'Honda', 'model' => 'Civic', 'year' => 2023,
            'color' => 'Blue', 'plate_number' => 'TEST-002', 'type' => 'sedan',
            'transmission' => 'automatic', 'seats' => 5, 'price_per_day' => 300,
        ])->assertStatus(403);
    }

    public function test_owner_without_agency_cannot_create_car(): void
    {
        // Owner exists but has no agency yet.
        $owner = $this->makeUser('agency_owner');

        $this->actingAs($owner)->postJson('/api/cars', [
            'brand' => 'Honda', 'model' => 'Civic', 'year' => 2023,
            'color' => 'Blue', 'plate_number' => 'TEST-003', 'type' => 'sedan',
            'transmission' => 'automatic', 'seats' => 5, 'price_per_day' => 300,
        ])->assertStatus(400);
    }

    // -------------------------------------------------------------------------
    // UPDATE CAR
    // -------------------------------------------------------------------------

    public function test_owner_can_update_own_car(): void
    {
        $city   = $this->makeCity();
        $owner  = $this->makeUser('agency_owner');
        $agency = $this->makeAgency($owner, $city);
        $car    = $this->makeCar($agency, $city);

        $response = $this->actingAs($owner)->putJson("/api/cars/{$car->id}", [
            'price_per_day' => 999,
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.price_per_day', '999.00');
    }

    public function test_owner_cannot_update_another_owners_car(): void
    {
        $city    = $this->makeCity();
        $owner1  = $this->makeUser('agency_owner');
        $owner2  = $this->makeUser('agency_owner');
        $agency1 = $this->makeAgency($owner1, $city);
        $agency2 = $this->makeAgency($owner2, $city);
        $car     = $this->makeCar($agency1, $city);

        // owner2 trying to edit owner1's car must be blocked by CarPolicy.
        $this->actingAs($owner2)->putJson("/api/cars/{$car->id}", [
            'price_per_day' => 1,
        ])->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // DELETE CAR
    // -------------------------------------------------------------------------

    public function test_owner_can_delete_car_without_active_reservations(): void
    {
        $city   = $this->makeCity();
        $owner  = $this->makeUser('agency_owner');
        $agency = $this->makeAgency($owner, $city);
        $car    = $this->makeCar($agency, $city);

        $this->actingAs($owner)->deleteJson("/api/cars/{$car->id}")
             ->assertStatus(200);

        // Soft-deleted car should not appear in regular queries.
        $this->assertSoftDeleted('cars', ['id' => $car->id]);
    }

    public function test_cannot_delete_car_with_active_reservations(): void
    {
        $city   = $this->makeCity();
        $owner  = $this->makeUser('agency_owner');
        $agency = $this->makeAgency($owner, $city);
        $car    = $this->makeCar($agency, $city);
        $client = $this->makeUser('client');

        Reservation::create([
            'id' => Str::uuid(), 'client_id' => $client->id,
            'car_id' => $car->id, 'agency_id' => $agency->id,
            'start_date' => Carbon::now()->addDay(),
            'end_date'   => Carbon::now()->addDays(5),
            'price_per_day_snapshot' => 200, 'total_amount' => 800,
            'status' => 'confirmed',
        ]);

        // The controller must block deletion when active reservations exist.
        $this->actingAs($owner)->deleteJson("/api/cars/{$car->id}")
             ->assertStatus(400);
    }

    // -------------------------------------------------------------------------
    // IMAGES
    // -------------------------------------------------------------------------

    public function test_owner_can_add_image_to_own_car(): void
    {
        $city   = $this->makeCity();
        $owner  = $this->makeUser('agency_owner');
        $agency = $this->makeAgency($owner, $city);
        $car    = $this->makeCar($agency, $city);

        $response = $this->actingAs($owner)->postJson("/api/cars/{$car->id}/images", [
            'image_url'  => 'https://example.com/car.jpg',
            'is_primary' => true,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('car_images', ['car_id' => $car->id]);
    }

    public function test_adding_primary_image_demotes_previous_primary(): void
    {
        $city   = $this->makeCity();
        $owner  = $this->makeUser('agency_owner');
        $agency = $this->makeAgency($owner, $city);
        $car    = $this->makeCar($agency, $city);

        // Add first primary image.
        $first = CarImage::create([
            'id' => Str::uuid(), 'car_id' => $car->id,
            'image_url' => 'https://example.com/first.jpg', 'is_primary' => true,
        ]);

        // Add second image also marked as primary — the first must be demoted.
        $this->actingAs($owner)->postJson("/api/cars/{$car->id}/images", [
            'image_url'  => 'https://example.com/second.jpg',
            'is_primary' => true,
        ])->assertStatus(201);

        // The first image must no longer be primary.
        $this->assertDatabaseHas('car_images', ['id' => $first->id, 'is_primary' => false]);
    }

    public function test_owner_can_remove_image(): void
    {
        $city   = $this->makeCity();
        $owner  = $this->makeUser('agency_owner');
        $agency = $this->makeAgency($owner, $city);
        $car    = $this->makeCar($agency, $city);
        $image  = CarImage::create([
            'id' => Str::uuid(), 'car_id' => $car->id,
            'image_url' => 'https://example.com/car.jpg', 'is_primary' => false,
        ]);

        $this->actingAs($owner)->deleteJson("/api/cars/{$car->id}/images/{$image->id}")
             ->assertStatus(200);

        $this->assertDatabaseMissing('car_images', ['id' => $image->id]);
    }

    // -------------------------------------------------------------------------
    // MAINTENANCE
    // -------------------------------------------------------------------------

    public function test_owner_can_schedule_maintenance(): void
    {
        $city   = $this->makeCity();
        $owner  = $this->makeUser('agency_owner');
        $agency = $this->makeAgency($owner, $city);
        $car    = $this->makeCar($agency, $city);

        $response = $this->actingAs($owner)->postJson("/api/cars/{$car->id}/maintenance", [
            'start_date' => '2027-09-01',
            'end_date'   => '2027-09-05',
            'reason'     => 'Oil change',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('car_maintenance_periods', ['car_id' => $car->id]);
    }

    public function test_cannot_schedule_maintenance_during_active_reservation(): void
    {
        $city   = $this->makeCity();
        $owner  = $this->makeUser('agency_owner');
        $agency = $this->makeAgency($owner, $city);
        $car    = $this->makeCar($agency, $city);
        $client = $this->makeUser('client');

        Reservation::create([
            'id' => Str::uuid(), 'client_id' => $client->id,
            'car_id' => $car->id, 'agency_id' => $agency->id,
            'start_date' => '2027-09-01', 'end_date' => '2027-09-10',
            'price_per_day_snapshot' => 200, 'total_amount' => 1800,
            'status' => 'confirmed',
        ]);

        // Trying to schedule maintenance inside the reservation window must be rejected.
        $this->actingAs($owner)->postJson("/api/cars/{$car->id}/maintenance", [
            'start_date' => '2027-09-03',
            'end_date'   => '2027-09-06',
        ])->assertStatus(400);
    }
}
