<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

// Tests for: POST /register, POST /login, POST /logout, GET /me
class AuthTest extends TestCase
{
    // RefreshDatabase rolls back all DB changes after each test so tests don't affect each other.
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // REGISTER
    // -------------------------------------------------------------------------

    public function test_user_can_register(): void
    {
        $response = $this->postJson('/api/register', [
            'first_name'            => 'John',
            'last_name'             => 'Doe',
            'email'                 => 'john@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['user', 'token', 'message']);

        // Confirm the user was actually persisted with the client role.
        $this->assertDatabaseHas('users', [
            'email' => 'john@example.com',
            'role'  => 'client',
        ]);
    }

    public function test_register_requires_all_fields(): void
    {
        // Submitting an empty body must return 422 Unprocessable Content.
        $this->postJson('/api/register', [])->assertStatus(422);
    }

    public function test_agency_owner_can_register(): void
    {
        $response = $this->postJson('/api/register', [
            'first_name'            => 'Jane',
            'last_name'             => 'Owner',
            'email'                 => 'owner@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'role'                  => 'agency_owner',
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['user', 'token', 'message']);

        $this->assertDatabaseHas('users', [
            'email' => 'owner@example.com',
            'role'  => 'agency_owner',
        ]);
    }

    public function test_register_rejects_mismatched_password_confirmation(): void
    {
        $this->postJson('/api/register', [
            'first_name'            => 'John',
            'last_name'             => 'Doe',
            'email'                 => 'john@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'different999',
        ])->assertStatus(422);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        // Create a user first so the email is already taken.
        User::create([
            'id'         => Str::uuid(),
            'first_name' => 'Existing',
            'last_name'  => 'User',
            'email'      => 'john@example.com',
            'password'   => bcrypt('secret'),
            'role'       => 'client',
        ]);

        $this->postJson('/api/register', [
            'first_name' => 'John',
            'last_name'  => 'Doe',
            'email'      => 'john@example.com',
            'password'   => 'password123',
        ])->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // LOGIN
    // -------------------------------------------------------------------------

    public function test_user_can_login(): void
    {
        // Create the user with a known password so we can test the login check.
        User::create([
            'id'         => Str::uuid(),
            'first_name' => 'John',
            'last_name'  => 'Doe',
            'email'      => 'john@example.com',
            'password'   => bcrypt('password123'),
            'role'       => 'client',
        ]);

        $response = $this->postJson('/api/login', [
            'email'    => 'john@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['user', 'token']);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::create([
            'id'         => Str::uuid(),
            'first_name' => 'John',
            'last_name'  => 'Doe',
            'email'      => 'john@example.com',
            'password'   => bcrypt('correct'),
            'role'       => 'client',
        ]);

        $this->postJson('/api/login', [
            'email'    => 'john@example.com',
            'password' => 'wrong',
        ])->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // LOGOUT
    // -------------------------------------------------------------------------

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::create([
            'id'         => Str::uuid(),
            'first_name' => 'John',
            'last_name'  => 'Doe',
            'email'      => 'john@example.com',
            'password'   => bcrypt('password123'),
            'role'       => 'client',
        ]);

        // We must use a real Sanctum token here because actingAs() creates a TransientToken
        // which has no delete() method — causing a fatal error in the logout controller.
        $token = $user->createToken('test_token')->plainTextToken;

        $this->withToken($token)
             ->postJson('/api/logout')
             ->assertStatus(200)
             ->assertJson(['message' => 'Logged out successfully']);
    }

    // -------------------------------------------------------------------------
    // ME
    // -------------------------------------------------------------------------

    public function test_authenticated_user_gets_own_profile(): void
    {
        $user = User::create([
            'id'         => Str::uuid(),
            'first_name' => 'John',
            'last_name'  => 'Doe',
            'email'      => 'john@example.com',
            'password'   => bcrypt('password123'),
            'role'       => 'client',
        ]);

        $response = $this->actingAs($user)->getJson('/api/me');

        $response->assertStatus(200)
                 ->assertJsonPath('user.email', 'john@example.com');
    }

    public function test_unauthenticated_request_to_me_returns_401(): void
    {
        $this->getJson('/api/me')->assertStatus(401);
    }
}
