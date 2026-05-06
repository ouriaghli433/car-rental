<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\City;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

// Tests for: GET /agencies, GET /agencies/{id}, POST /agencies, PUT /agencies/{id}
class AgencyTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // HELPERS: create the common test models used across multiple tests
    // -------------------------------------------------------------------------

    private function makeCity(): City
    {
        return City::create([
            'id'        => Str::uuid(),
            'name'      => 'Tangier',
            'region'    => 'Tanger-Tetouan',
            'country'   => 'Morocco',
            'is_active' => true,
        ]);
    }

    private function makeUser(string $role = 'client', string $status = 'active'): User
    {
        return User::create([
            'id'         => Str::uuid(),
            'first_name' => 'Test',
            'last_name'  => 'User',
            'email'      => Str::random(6) . '@test.com',
            'password'   => bcrypt('password'),
            'role'       => $role,
            'status'     => $status,
        ]);
    }

    private function makeAgency(User $owner, City $city, string $status = 'approved'): Agency
    {
        return Agency::create([
            'id'       => Str::uuid(),
            'owner_id' => $owner->id,
            'city_id'  => $city->id,
            'name'     => 'Test Agency ' . Str::random(4),
            'slug'     => 'test-agency-' . Str::random(4),
            'address'  => '123 Main St',
            'phone'    => '0600000000',
            'email'    => Str::random(6) . '@agency.com',
            'status'   => $status,
            'balance'  => 1000,
        ]);
    }

    // -------------------------------------------------------------------------
    // PUBLIC LISTING
    // -------------------------------------------------------------------------

    public function test_anyone_can_list_agencies(): void
    {
        $city  = $this->makeCity();
        $owner = $this->makeUser('agency_owner');
        // Only approved agencies show in the public listing.
        $this->makeAgency($owner, $city, 'approved');
        $this->makeAgency($this->makeUser('agency_owner'), $city, 'pending');

        $response = $this->getJson('/api/agencies');

        $response->assertStatus(200);
        // Pending agency must not appear in the public listing.
        $this->assertCount(1, $response->json('data'));
    }

    public function test_anyone_can_view_an_agency(): void
    {
        $city   = $this->makeCity();
        $owner  = $this->makeUser('agency_owner');
        $agency = $this->makeAgency($owner, $city);

        $this->getJson("/api/agencies/{$agency->id}")
             ->assertStatus(200)
             // Cast to string because Str::uuid() returns a LazyUuidFromString object
             // while assertJsonPath compares with === against the JSON string value.
             ->assertJsonPath('data.id', (string) $agency->id);
    }

    public function test_show_returns_404_for_unknown_agency(): void
    {
        $this->getJson('/api/agencies/' . Str::uuid())->assertStatus(404);
    }

    // -------------------------------------------------------------------------
    // CREATE AGENCY
    // -------------------------------------------------------------------------

    public function test_agency_owner_can_create_agency(): void
    {
        $city  = $this->makeCity();
        $owner = $this->makeUser('agency_owner');

        $response = $this->actingAs($owner)->postJson('/api/agencies', [
            'name'    => 'My Great Agency',
            'city_id' => $city->id,
            'address' => '1 Rue Hassan II',
            'phone'   => '0600000099',
            'email'   => 'myagency@test.com',
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.status', 'pending'); // starts pending until admin approves

        $this->assertDatabaseHas('agencies', ['name' => 'My Great Agency']);
    }

    public function test_agency_owner_cannot_create_second_agency(): void
    {
        $city  = $this->makeCity();
        $owner = $this->makeUser('agency_owner');
        $this->makeAgency($owner, $city);

        // Second attempt must be rejected because one owner = one agency.
        $this->actingAs($owner)->postJson('/api/agencies', [
            'name'    => 'Another Agency',
            'city_id' => $city->id,
            'address' => '2 Rue Hassan II',
            'phone'   => '0600000088',
            'email'   => 'another@test.com',
        ])->assertStatus(400);
    }

    public function test_client_cannot_create_agency(): void
    {
        $city   = $this->makeCity();
        $client = $this->makeUser('client');

        $this->actingAs($client)->postJson('/api/agencies', [
            'name'    => 'Sneaky Agency',
            'city_id' => $city->id,
            'address' => '3 Rue Hassan II',
            'phone'   => '0600000077',
            'email'   => 'sneaky@test.com',
        ])->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // UPDATE AGENCY
    // -------------------------------------------------------------------------

    public function test_owner_update_stores_pending_changes_not_applied_immediately(): void
    {
        $city   = $this->makeCity();
        $owner  = $this->makeUser('agency_owner');
        $agency = $this->makeAgency($owner, $city);

        $response = $this->actingAs($owner)->putJson("/api/agencies/{$agency->id}", [
            'address' => 'New Street 999',
        ]);

        $response->assertStatus(200);

        // Live agency address must NOT change — it waits for admin approval.
        $this->assertEquals('123 Main St', $agency->fresh()->address);

        // The change must be stored in pending_changes.
        $pending = $agency->fresh()->pending_changes;
        $this->assertNotNull($pending);
        $this->assertEquals('New Street 999', $pending['address']);
    }

    public function test_owner_cannot_update_another_owners_agency(): void
    {
        $city    = $this->makeCity();
        $owner1  = $this->makeUser('agency_owner');
        $owner2  = $this->makeUser('agency_owner');
        $agency  = $this->makeAgency($owner1, $city);

        // owner2 should be denied by AgencyPolicy.
        $this->actingAs($owner2)->putJson("/api/agencies/{$agency->id}", [
            'address' => 'Hacked Address',
        ])->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // ADMIN APPROVAL FLOW
    // -------------------------------------------------------------------------

    public function test_admin_can_approve_pending_agency_changes(): void
    {
        $city   = $this->makeCity();
        $owner  = $this->makeUser('agency_owner');
        $admin  = $this->makeUser('admin');
        $agency = $this->makeAgency($owner, $city);

        // Owner submits an update.
        $this->actingAs($owner)->putJson("/api/agencies/{$agency->id}", [
            'address' => 'Approved Street 1',
        ]);

        // Admin approves it.
        $this->actingAs($admin)
             ->postJson("/api/admin/agencies/{$agency->id}/approve-changes")
             ->assertStatus(200);

        $fresh = $agency->fresh();
        // Change must now be live.
        $this->assertEquals('Approved Street 1', $fresh->address);
        // Queue must be cleared.
        $this->assertNull($fresh->pending_changes);
    }

    public function test_admin_can_reject_pending_agency_changes(): void
    {
        $city   = $this->makeCity();
        $owner  = $this->makeUser('agency_owner');
        $admin  = $this->makeUser('admin');
        $agency = $this->makeAgency($owner, $city);

        $this->actingAs($owner)->putJson("/api/agencies/{$agency->id}", [
            'address' => 'Rejected Street 2',
        ]);

        $this->actingAs($admin)
             ->postJson("/api/admin/agencies/{$agency->id}/reject-changes")
             ->assertStatus(200);

        $fresh = $agency->fresh();
        // Original address must remain unchanged.
        $this->assertEquals('123 Main St', $fresh->address);
        // Queue must be cleared.
        $this->assertNull($fresh->pending_changes);
    }

    public function test_approve_returns_400_when_no_pending_changes(): void
    {
        $city   = $this->makeCity();
        $owner  = $this->makeUser('agency_owner');
        $admin  = $this->makeUser('admin');
        $agency = $this->makeAgency($owner, $city);

        // No pending changes submitted — approve must fail gracefully.
        $this->actingAs($admin)
             ->postJson("/api/admin/agencies/{$agency->id}/approve-changes")
             ->assertStatus(400);
    }

    public function test_agency_owner_can_see_own_pending_changes(): void
    {
        $city   = $this->makeCity();
        $owner  = $this->makeUser('agency_owner');
        $agency = $this->makeAgency($owner, $city);

        $this->actingAs($owner)->putJson("/api/agencies/{$agency->id}", [
            'phone' => '0699999999',
        ]);

        $response = $this->actingAs($owner)->getJson("/api/agencies/{$agency->id}");

        $response->assertStatus(200)
                 ->assertJsonPath('data.has_pending_changes', true)
                 ->assertJsonPath('data.pending_changes.phone', '0699999999');
    }

    public function test_stranger_client_cannot_see_pending_changes(): void
    {
        $city    = $this->makeCity();
        $owner   = $this->makeUser('agency_owner');
        $client  = $this->makeUser('client');
        $agency  = $this->makeAgency($owner, $city);

        $this->actingAs($owner)->putJson("/api/agencies/{$agency->id}", [
            'phone' => '0699999999',
        ]);

        // A regular client must not see another agency's pending_changes.
        $response = $this->actingAs($client)->getJson("/api/agencies/{$agency->id}");
        $response->assertStatus(200)
                 ->assertJsonPath('data.pending_changes', null);
    }
}
