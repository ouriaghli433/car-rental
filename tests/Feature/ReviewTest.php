<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Car;
use App\Models\City;
use App\Models\Reservation;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

// Tests for: POST /reservations/{id}/review, GET /cars/{id}/reviews, GET /agencies/{id}/reviews
class ReviewTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------------------------

    private function makeScenario(): array
    {
        $city   = City::create(['id' => Str::uuid(), 'name' => 'Tangier', 'region' => 'TTA', 'country' => 'Morocco', 'is_active' => true]);
        $owner  = User::create(['id' => Str::uuid(), 'first_name' => 'Owner', 'last_name' => 'A', 'email' => 'owner@test.com', 'password' => bcrypt('p'), 'role' => 'agency_owner', 'status' => 'active']);
        $client = User::create(['id' => Str::uuid(), 'first_name' => 'Client', 'last_name' => 'B', 'email' => 'client@test.com', 'password' => bcrypt('p'), 'role' => 'client', 'status' => 'active']);
        $agency = Agency::create(['id' => Str::uuid(), 'owner_id' => $owner->id, 'city_id' => $city->id, 'name' => 'Agency', 'slug' => 'agency', 'address' => '1 St', 'phone' => '060', 'email' => 'a@a.com', 'status' => 'approved', 'balance' => 1000]);
        $car    = Car::create(['id' => Str::uuid(), 'agency_id' => $agency->id, 'city_id' => $city->id, 'brand' => 'Toyota', 'model' => 'Yaris', 'year' => 2022, 'color' => 'Red', 'plate_number' => 'REV-001', 'type' => 'sedan', 'transmission' => 'automatic', 'seats' => 5, 'price_per_day' => 200, 'status' => 'available']);

        return compact('city', 'owner', 'client', 'agency', 'car');
    }

    private function makeReservation(array $data, string $status = 'completed'): Reservation
    {
        return Reservation::create([
            'id' => Str::uuid(), 'client_id' => $data['client']->id,
            'car_id' => $data['car']->id, 'agency_id' => $data['agency']->id,
            'start_date' => '2026-01-01', 'end_date' => '2026-01-05',
            'price_per_day_snapshot' => 200, 'total_amount' => 800,
            'status' => $status,
        ]);
    }

    // -------------------------------------------------------------------------
    // POST REVIEW
    // -------------------------------------------------------------------------

    public function test_client_can_review_completed_reservation(): void
    {
        $data        = $this->makeScenario();
        $reservation = $this->makeReservation($data, 'completed');

        $response = $this->actingAs($data['client'])->postJson("/api/reservations/{$reservation->id}/review", [
            'car_rating'    => 5,
            'agency_rating' => 4,
            'comment'       => 'Great experience!',
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.car_rating', 5);

        $this->assertDatabaseHas('reviews', ['reservation_id' => $reservation->id]);
    }

    public function test_client_cannot_review_pending_reservation(): void
    {
        $data        = $this->makeScenario();
        $reservation = $this->makeReservation($data, 'pending');

        // Pending reservations have not been fulfilled yet — no review allowed.
        $this->actingAs($data['client'])->postJson("/api/reservations/{$reservation->id}/review", [
            'car_rating'    => 5,
            'agency_rating' => 4,
        ])->assertStatus(400);
    }

    public function test_client_cannot_review_same_reservation_twice(): void
    {
        $data        = $this->makeScenario();
        $reservation = $this->makeReservation($data, 'completed');

        // First review — should succeed.
        $this->actingAs($data['client'])->postJson("/api/reservations/{$reservation->id}/review", [
            'car_rating' => 5, 'agency_rating' => 4,
        ])->assertStatus(201);

        // Second review on the same reservation — must be rejected.
        $this->actingAs($data['client'])->postJson("/api/reservations/{$reservation->id}/review", [
            'car_rating' => 3, 'agency_rating' => 3,
        ])->assertStatus(400);
    }

    public function test_other_client_cannot_review_someones_reservation(): void
    {
        $data        = $this->makeScenario();
        $reservation = $this->makeReservation($data, 'completed');

        // A different client who did not make this reservation must be denied.
        $stranger = User::create(['id' => Str::uuid(), 'first_name' => 'S', 'last_name' => 'S', 'email' => 'stranger@test.com', 'password' => bcrypt('p'), 'role' => 'client', 'status' => 'active']);

        $this->actingAs($stranger)->postJson("/api/reservations/{$reservation->id}/review", [
            'car_rating' => 5, 'agency_rating' => 4,
        ])->assertStatus(403);
    }

    public function test_review_recalculates_car_and_agency_ratings(): void
    {
        $data        = $this->makeScenario();
        $reservation = $this->makeReservation($data, 'completed');

        $this->actingAs($data['client'])->postJson("/api/reservations/{$reservation->id}/review", [
            'car_rating'    => 4,
            'agency_rating' => 5,
        ]);

        // The car and agency avg_rating fields must be updated after the review is posted.
        $this->assertDatabaseHas('cars',     ['id' => $data['car']->id,    'avg_rating' => 4.0, 'total_reviews' => 1]);
        $this->assertDatabaseHas('agencies', ['id' => $data['agency']->id, 'avg_rating' => 5.0, 'total_reviews' => 1]);
    }

    // -------------------------------------------------------------------------
    // PUBLIC REVIEW LISTINGS
    // -------------------------------------------------------------------------

    public function test_anyone_can_list_reviews_for_a_car(): void
    {
        $data        = $this->makeScenario();
        $reservation = $this->makeReservation($data, 'completed');

        Review::create([
            'id' => Str::uuid(), 'reservation_id' => $reservation->id,
            'car_rating' => 5, 'agency_rating' => 4,
        ]);

        $this->getJson("/api/cars/{$data['car']->id}/reviews")
             ->assertStatus(200)
             ->assertJsonStructure(['data']);
    }

    public function test_anyone_can_list_reviews_for_an_agency(): void
    {
        $data        = $this->makeScenario();
        $reservation = $this->makeReservation($data, 'completed');

        Review::create([
            'id' => Str::uuid(), 'reservation_id' => $reservation->id,
            'car_rating' => 5, 'agency_rating' => 4,
        ]);

        $this->getJson("/api/agencies/{$data['agency']->id}/reviews")
             ->assertStatus(200)
             ->assertJsonStructure(['data']);
    }
}
