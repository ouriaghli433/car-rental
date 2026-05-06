<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Car;
use App\Models\City;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

// Tests for: cancellation throttle (2/day client, 2/day agency) and pickup confirmation.
class ReservationTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------------------------

    private function makeUser(string $role = 'client', string $status = 'active'): User
    {
        return User::create([
            'id' => Str::uuid(), 'first_name' => 'Test', 'last_name' => 'User',
            'email' => Str::random(6) . '@test.com',
            'password' => bcrypt('password'), 'role' => $role, 'status' => $status,
        ]);
    }

    private function makeAgency(User $owner, string $status = 'approved'): Agency
    {
        $city = City::create([
            'id' => Str::uuid(), 'name' => 'Tangier', 'region' => 'TTA',
            'country' => 'Morocco', 'is_active' => true,
        ]);
        return Agency::create([
            'id' => Str::uuid(), 'owner_id' => $owner->id, 'city_id' => $city->id,
            'name' => 'Agency ' . Str::random(4), 'slug' => Str::random(8),
            'address' => '1 Main St', 'phone' => '060',
            'email' => Str::random(6) . '@agency.com',
            'status' => $status, 'balance' => 1000,
        ]);
    }

    private function makeCar(Agency $agency): Car
    {
        return Car::create([
            'id' => Str::uuid(), 'agency_id' => $agency->id, 'city_id' => $agency->city_id,
            'brand' => 'Toyota', 'model' => 'Corolla', 'year' => 2022,
            'color' => 'White', 'plate_number' => strtoupper(Str::random(8)),
            'type' => 'sedan', 'transmission' => 'automatic',
            'seats' => 5, 'price_per_day' => 200, 'status' => 'available',
        ]);
    }

    private function makeReservation(User $client, Car $car, Agency $agency, string $status = 'confirmed'): Reservation
    {
        return Reservation::create([
            'id' => Str::uuid(), 'client_id' => $client->id,
            'car_id' => $car->id, 'agency_id' => $agency->id,
            'start_date' => '2027-07-01', 'end_date' => '2027-07-05',
            'price_per_day_snapshot' => 200, 'total_amount' => 800,
            'status' => $status,
        ]);
    }

    // -------------------------------------------------------------------------
    // CLIENT CANCELLATION THROTTLE (max 2/day)
    // -------------------------------------------------------------------------

    public function test_client_can_cancel_up_to_2_times_per_day(): void
    {
        $owner  = $this->makeUser('agency_owner');
        $agency = $this->makeAgency($owner);
        $client = $this->makeUser('client');

        // First cancellation — should succeed.
        $res1 = $this->makeReservation($client, $this->makeCar($agency), $agency, 'pending');
        $this->actingAs($client)->postJson("/api/reservations/{$res1->id}/cancel")
             ->assertStatus(200);

        // Second cancellation — should also succeed (limit is 2).
        $res2 = $this->makeReservation($client, $this->makeCar($agency), $agency, 'pending');
        $this->actingAs($client)->postJson("/api/reservations/{$res2->id}/cancel")
             ->assertStatus(200);
    }

    public function test_client_is_blocked_after_2_cancellations(): void
    {
        $owner  = $this->makeUser('agency_owner');
        $agency = $this->makeAgency($owner);
        $client = $this->makeUser('client');

        // Do 2 successful cancellations first.
        $res1 = $this->makeReservation($client, $this->makeCar($agency), $agency, 'pending');
        $this->actingAs($client)->postJson("/api/reservations/{$res1->id}/cancel");
        $res2 = $this->makeReservation($client, $this->makeCar($agency), $agency, 'pending');
        $this->actingAs($client)->postJson("/api/reservations/{$res2->id}/cancel");

        // Third cancellation must be rejected — limit exceeded.
        $res3 = $this->makeReservation($client, $this->makeCar($agency), $agency, 'pending');
        $this->actingAs($client)->postJson("/api/reservations/{$res3->id}/cancel")
             ->assertStatus(403);

        // The client must now be marked as blocked in the DB.
        $this->assertNotNull($client->fresh()->blocked_until);
    }

    // -------------------------------------------------------------------------
    // AGENCY CANCELLATION THROTTLE (max 2/day)
    // -------------------------------------------------------------------------

    public function test_agency_is_blocked_after_2_cancellations(): void
    {
        $owner  = $this->makeUser('agency_owner');
        $agency = $this->makeAgency($owner);
        $client = $this->makeUser('client');

        // Two successful agency cancellations (mark cancelled_by=agency manually to simulate prior cancels).
        $res1 = $this->makeReservation($client, $this->makeCar($agency), $agency, 'confirmed');
        $res1->update(['cancelled_by' => 'agency', 'cancelled_at' => now(), 'status' => 'cancelled']);

        $res2 = $this->makeReservation($client, $this->makeCar($agency), $agency, 'confirmed');
        $res2->update(['cancelled_by' => 'agency', 'cancelled_at' => now(), 'status' => 'cancelled']);

        // Third agency cancellation today must be rejected.
        $res3 = $this->makeReservation($client, $this->makeCar($agency), $agency, 'confirmed');
        $this->actingAs($owner)->postJson("/api/reservations/{$res3->id}/cancel")
             ->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // PICKUP CONFIRMATION
    // -------------------------------------------------------------------------

    public function test_client_can_confirm_pickup_of_confirmed_reservation(): void
    {
        $owner  = $this->makeUser('agency_owner');
        $agency = $this->makeAgency($owner);
        $client = $this->makeUser('client');
        $res    = $this->makeReservation($client, $this->makeCar($agency), $agency, 'confirmed');

        $response = $this->actingAs($client)->postJson("/api/reservations/{$res->id}/pickup");

        $response->assertStatus(200)
                 ->assertJsonStructure(['message', 'picked_up_at']);

        // The picked_up_at timestamp must now be set in the DB.
        $this->assertNotNull($res->fresh()->picked_up_at);
    }

    public function test_client_cannot_confirm_pickup_of_pending_reservation(): void
    {
        $owner  = $this->makeUser('agency_owner');
        $agency = $this->makeAgency($owner);
        $client = $this->makeUser('client');
        $res    = $this->makeReservation($client, $this->makeCar($agency), $agency, 'pending');

        // Agency hasn't confirmed yet — pickup must be rejected.
        $this->actingAs($client)->postJson("/api/reservations/{$res->id}/pickup")
             ->assertStatus(400);
    }

    public function test_client_cannot_confirm_pickup_twice(): void
    {
        $owner  = $this->makeUser('agency_owner');
        $agency = $this->makeAgency($owner);
        $client = $this->makeUser('client');
        $res    = $this->makeReservation($client, $this->makeCar($agency), $agency, 'confirmed');

        // First pickup — succeeds.
        $this->actingAs($client)->postJson("/api/reservations/{$res->id}/pickup")->assertStatus(200);

        // Second pickup — must be rejected.
        $this->actingAs($client)->postJson("/api/reservations/{$res->id}/pickup")->assertStatus(400);
    }

    public function test_agency_owner_cannot_confirm_pickup(): void
    {
        $owner  = $this->makeUser('agency_owner');
        $agency = $this->makeAgency($owner);
        $client = $this->makeUser('client');
        $res    = $this->makeReservation($client, $this->makeCar($agency), $agency, 'confirmed');

        // Only the client can confirm pickup — the agency owner must be denied.
        $this->actingAs($owner)->postJson("/api/reservations/{$res->id}/pickup")
             ->assertStatus(403);
    }

    public function test_other_client_cannot_confirm_pickup(): void
    {
        $owner    = $this->makeUser('agency_owner');
        $agency   = $this->makeAgency($owner);
        $client   = $this->makeUser('client');
        $stranger = $this->makeUser('client');
        $res      = $this->makeReservation($client, $this->makeCar($agency), $agency, 'confirmed');

        // A different client must not be able to confirm someone else's pickup.
        $this->actingAs($stranger)->postJson("/api/reservations/{$res->id}/pickup")
             ->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // CAR LISTING: agency details must not be visible before reservation
    // -------------------------------------------------------------------------

    public function test_car_listing_does_not_expose_agency_name(): void
    {
        $owner  = $this->makeUser('agency_owner');
        $agency = $this->makeAgency($owner);
        $this->makeCar($agency);

        $response = $this->getJson('/api/cars');

        $response->assertStatus(200);

        // 'agency' key must not appear in any car in the public listing.
        foreach ($response->json('data') as $car) {
            $this->assertArrayNotHasKey('agency', $car,
                'Agency details must not be exposed in the public car listing.');
        }
    }

    // -------------------------------------------------------------------------
    // HAPPY PATH: full reservation lifecycle
    // client creates → agency confirms → client picks up → scheduler completes → client reviews
    // -------------------------------------------------------------------------

    public function test_full_reservation_lifecycle(): void
    {
        $owner  = $this->makeUser('agency_owner');
        $agency = $this->makeAgency($owner);
        $car    = $this->makeCar($agency);
        $client = $this->makeUser('client');

        // Step 1 — client creates a reservation (datetime format, future dates).
        $storeResponse = $this->actingAs($client)->postJson('/api/reservations', [
            'car_id'     => (string) $car->id,
            'start_date' => '2027-08-01 10:00',
            'end_date'   => '2027-08-05 10:00',
        ]);
        $storeResponse->assertStatus(200);
        $reservationId = $storeResponse->json('data.id');
        $this->assertNotNull($reservationId);

        // Reservation must start as pending with expires_at set.
        $reservation = Reservation::find($reservationId);
        $this->assertEquals('pending', $reservation->status);
        $this->assertNotNull($reservation->expires_at);

        // Step 2 — agency confirms the reservation (deducts 10% commission from balance).
        $balanceBefore = $agency->fresh()->balance;
        $this->actingAs($owner)->postJson("/api/reservations/{$reservationId}/confirm")
             ->assertStatus(200);

        $reservation->refresh();
        $this->assertEquals('confirmed', $reservation->status);
        $this->assertNotNull($reservation->confirmed_at);
        $this->assertNull($reservation->expires_at); // cleared on confirm
        // Commission = 10% of total_amount (4 days × 200 = 800 → 80).
        $this->assertEquals($balanceBefore - 80, $agency->fresh()->balance);

        // Step 3 — client confirms physical pickup.
        $this->actingAs($client)->postJson("/api/reservations/{$reservationId}/pickup")
             ->assertStatus(200)
             ->assertJsonStructure(['message', 'picked_up_at']);

        $reservation->refresh();
        $this->assertNotNull($reservation->picked_up_at);

        // Step 4 — cancel must be rejected after pickup.
        $this->actingAs($client)->postJson("/api/reservations/{$reservationId}/cancel")
             ->assertStatus(400);

        // Step 5 — simulate scheduler: mark reservation completed after end_date passes.
        $reservation->update([
            'status'       => 'completed',
            'completed_at' => now(),
            'end_date'     => now()->subDay(), // end_date in the past
        ]);

        // Step 6 — client can now post a review (requires completed status).
        $this->actingAs($client)->postJson("/api/reservations/{$reservationId}/review", [
            'car_rating'    => 5,
            'agency_rating' => 4,
            'comment'       => 'Great experience!',
        ])->assertStatus(201);
    }

    // -------------------------------------------------------------------------
    // CITIES: public endpoint returns active cities only
    // -------------------------------------------------------------------------

    public function test_cities_endpoint_returns_active_cities(): void
    {
        City::create([
            'id' => Str::uuid(), 'name' => 'Casablanca', 'region' => 'GC',
            'country' => 'Morocco', 'is_active' => true,
        ]);
        City::create([
            'id' => Str::uuid(), 'name' => 'Hidden City', 'region' => 'XX',
            'country' => 'Morocco', 'is_active' => false,
        ]);

        $response = $this->getJson('/api/cities');

        $response->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('Casablanca', $names);
        $this->assertNotContains('Hidden City', $names);
    }

    // -------------------------------------------------------------------------
    // RESERVATION RESOURCE: lifecycle timestamps are exposed
    // -------------------------------------------------------------------------

    public function test_reservation_resource_exposes_lifecycle_timestamps(): void
    {
        $owner  = $this->makeUser('agency_owner');
        $agency = $this->makeAgency($owner);
        $client = $this->makeUser('client');
        $res    = $this->makeReservation($client, $this->makeCar($agency), $agency, 'pending');
        $res->update(['expires_at' => now()->addHour()]);

        $response = $this->actingAs($client)->getJson("/api/reservations/{$res->id}");

        $response->assertStatus(200)
                 ->assertJsonStructure(['data' => ['expires_at', 'picked_up_at', 'confirmed_at', 'completed_at', 'cancelled_at']]);
    }

    // -------------------------------------------------------------------------
    // AUTH: blocked user cannot log in during block window
    // -------------------------------------------------------------------------

    public function test_blocked_user_cannot_login(): void
    {
        $client = $this->makeUser('client');
        $client->update(['blocked_until' => now()->addHours(24)]);

        $this->postJson('/api/login', [
            'email'    => $client->email,
            'password' => 'password',
        ])->assertStatus(403);
    }

    public function test_unblocked_user_can_login_after_block_expires(): void
    {
        $client = $this->makeUser('client');
        // Block window is in the past — login must succeed.
        $client->update(['blocked_until' => now()->subHour()]);

        $this->postJson('/api/login', [
            'email'    => $client->email,
            'password' => 'password',
        ])->assertStatus(200)->assertJsonStructure(['token']);
    }

    // -------------------------------------------------------------------------
    // SCHEDULER: reservations:complete command marks confirmed past reservations
    // -------------------------------------------------------------------------

    public function test_complete_scheduler_marks_confirmed_past_reservations(): void
    {
        $owner  = $this->makeUser('agency_owner');
        $agency = $this->makeAgency($owner);
        $client = $this->makeUser('client');

        // A reservation whose end_date is yesterday and was picked up — should be completed.
        $past = $this->makeReservation($client, $this->makeCar($agency), $agency, 'confirmed');
        $past->update(['end_date' => now()->subDay(), 'picked_up_at' => now()->subDays(3)]);

        // A reservation whose end_date is tomorrow — must NOT be completed yet.
        $future = $this->makeReservation($client, $this->makeCar($agency), $agency, 'confirmed');
        $future->update(['end_date' => now()->addDay()]);

        $this->artisan('reservations:complete')->assertSuccessful();

        $this->assertEquals('completed', $past->fresh()->status);
        $this->assertNotNull($past->fresh()->completed_at);
        $this->assertEquals('confirmed', $future->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // SCHEDULER: users:reset-cancel-counts resets daily counter
    // -------------------------------------------------------------------------

    public function test_reset_cancel_counts_command_zeroes_counters(): void
    {
        $client = $this->makeUser('client');
        $client->update(['cancel_count_today' => 2]);

        $this->artisan('users:reset-cancel-counts')->assertSuccessful();

        $this->assertEquals(0, $client->fresh()->cancel_count_today);
    }
}
