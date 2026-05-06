<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Car;
use App\Models\City;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

// Tests for: GET /admin/users, PATCH /admin/users/{id}/status,
//            GET /admin/agencies, PATCH /admin/agencies/{id}/status,
//            POST /admin/agencies/{id}/top-up, GET /admin/stats
class AdminTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------------------------

    private function makeAdmin(): User
    {
        return User::create([
            'id' => Str::uuid(), 'first_name' => 'Admin', 'last_name' => 'User',
            'email' => 'admin@test.com', 'password' => bcrypt('password'),
            'role' => 'admin', 'status' => 'active',
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

    private function makeAgency(User $owner, string $status = 'pending'): Agency
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
            'status' => $status, 'balance' => 0,
        ]);
    }

    // -------------------------------------------------------------------------
    // ACCESS CONTROL: non-admins must be blocked from all admin routes
    // -------------------------------------------------------------------------

    public function test_client_cannot_access_admin_routes(): void
    {
        $client = $this->makeUser('client');

        $this->actingAs($client)->getJson('/api/admin/users')->assertStatus(403);
        $this->actingAs($client)->getJson('/api/admin/agencies')->assertStatus(403);
        $this->actingAs($client)->getJson('/api/admin/stats')->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // USER MANAGEMENT
    // -------------------------------------------------------------------------

    public function test_admin_can_list_all_users(): void
    {
        $admin = $this->makeAdmin();
        $this->makeUser(); // create an extra user

        $response = $this->actingAs($admin)->getJson('/api/admin/users');

        $response->assertStatus(200);
        // At least the admin + the extra user should be in the list.
        $this->assertGreaterThanOrEqual(2, $response->json('total'));
    }

    public function test_admin_can_block_a_user(): void
    {
        $admin  = $this->makeAdmin();
        $client = $this->makeUser('client');

        $response = $this->actingAs($admin)->patchJson("/api/admin/users/{$client->id}/status", [
            'status' => 'blocked',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $client->id, 'status' => 'blocked']);
    }

    public function test_admin_can_activate_a_user(): void
    {
        $admin  = $this->makeAdmin();
        // Start with a blocked user.
        $client = User::create([
            'id' => Str::uuid(), 'first_name' => 'Blocked', 'last_name' => 'One',
            'email' => 'blocked@test.com', 'password' => bcrypt('p'),
            'role' => 'client', 'status' => 'blocked',
        ]);

        $this->actingAs($admin)->patchJson("/api/admin/users/{$client->id}/status", [
            'status' => 'active',
        ])->assertStatus(200);

        $this->assertDatabaseHas('users', ['id' => $client->id, 'status' => 'active']);
    }

    public function test_admin_cannot_change_own_status(): void
    {
        $admin = $this->makeAdmin();

        // Admins changing their own status would be a self-lockout risk.
        $this->actingAs($admin)->patchJson("/api/admin/users/{$admin->id}/status", [
            'status' => 'blocked',
        ])->assertStatus(400);
    }

    public function test_update_status_rejects_invalid_values(): void
    {
        $admin  = $this->makeAdmin();
        $client = $this->makeUser('client');

        $this->actingAs($admin)->patchJson("/api/admin/users/{$client->id}/status", [
            'status' => 'superadmin', // not a valid status
        ])->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // AGENCY MANAGEMENT
    // -------------------------------------------------------------------------

    public function test_admin_can_list_all_agencies(): void
    {
        $admin = $this->makeAdmin();
        $owner = $this->makeUser('agency_owner');
        $this->makeAgency($owner, 'pending');

        $response = $this->actingAs($admin)->getJson('/api/admin/agencies');

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $response->json('meta.total'));
    }

    public function test_admin_can_filter_agencies_by_status(): void
    {
        $admin         = $this->makeAdmin();
        $pendingOwner  = $this->makeUser('agency_owner');
        $approvedOwner = $this->makeUser('agency_owner');
        $this->makeAgency($pendingOwner, 'pending');
        $this->makeAgency($approvedOwner, 'approved');

        $response = $this->actingAs($admin)->getJson('/api/admin/agencies?status=pending');

        $response->assertStatus(200);
        // Only the pending agency should be in the result set.
        $this->assertEquals(1, $response->json('meta.total'));
        $this->assertEquals('pending', $response->json('data.0.status'));
    }

    public function test_admin_can_approve_an_agency(): void
    {
        $admin  = $this->makeAdmin();
        $owner  = $this->makeUser('agency_owner');
        $agency = $this->makeAgency($owner, 'pending');

        $this->actingAs($admin)->patchJson("/api/admin/agencies/{$agency->id}/status", [
            'status' => 'approved',
        ])->assertStatus(200);

        $this->assertDatabaseHas('agencies', ['id' => $agency->id, 'status' => 'approved']);
    }

    public function test_admin_can_reject_an_agency(): void
    {
        $admin  = $this->makeAdmin();
        $owner  = $this->makeUser('agency_owner');
        $agency = $this->makeAgency($owner, 'pending');

        $this->actingAs($admin)->patchJson("/api/admin/agencies/{$agency->id}/status", [
            'status' => 'rejected',
        ])->assertStatus(200);

        $this->assertDatabaseHas('agencies', ['id' => $agency->id, 'status' => 'rejected']);
    }

    // -------------------------------------------------------------------------
    // TOP-UP BALANCE
    // -------------------------------------------------------------------------

    public function test_admin_can_top_up_agency_balance(): void
    {
        $admin  = $this->makeAdmin();
        $owner  = $this->makeUser('agency_owner');
        $agency = $this->makeAgency($owner, 'approved');

        $response = $this->actingAs($admin)->postJson("/api/admin/agencies/{$agency->id}/top-up", [
            'amount' => 500,
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('balance_after', 500); // started at 0, added 500

        // A payment record must be created for the top-up so the balance change is auditable.
        $this->assertDatabaseHas('payments', [
            'agency_id' => $agency->id,
            'type'      => 'top_up',
            'amount'    => 500,
        ]);
    }

    public function test_top_up_requires_positive_amount(): void
    {
        $admin  = $this->makeAdmin();
        $owner  = $this->makeUser('agency_owner');
        $agency = $this->makeAgency($owner);

        $this->actingAs($admin)->postJson("/api/admin/agencies/{$agency->id}/top-up", [
            'amount' => 0, // zero is not a valid top-up
        ])->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // STATS
    // -------------------------------------------------------------------------

    public function test_admin_can_view_platform_stats(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->getJson('/api/admin/stats');

        // All top-level keys must be present in the stats response.
        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'users'         => ['total', 'active', 'blocked'],
                     'agencies'      => ['total', 'approved', 'pending', 'rejected'],
                     'cars'          => ['total', 'available', 'rented', 'maintenance'],
                     'reservations',
                     'total_revenue',
                 ]);
    }
}
