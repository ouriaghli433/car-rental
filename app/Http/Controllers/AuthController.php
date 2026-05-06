<?php

namespace App\Http\Controllers;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name'  => 'required|string|max:100',
            'email'      => 'required|email|unique:users',
            // min:8 — short passwords are a common attack vector.
            // confirmed — requires a matching password_confirmation field to prevent typos.
            'password'   => 'required|min:8|confirmed',
            // Clients register as 'client'; agency owners register as 'agency_owner'.
            // Admins cannot self-register — they must be created directly in the DB.
            'role'       => 'sometimes|in:client,agency_owner',
        ]);

        $user = User::create([
            'id'         => Str::uuid(),
            'first_name' => $data['first_name'],
            'last_name'  => $data['last_name'],
            'email'      => $data['email'],
            'password'   => Hash::make($data['password']),
            'role'       => $data['role'] ?? 'client',
        ]);

        $token = $user->createToken('api_token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
            'message' => 'user created',
        ]);
    }
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials'
            ], 401);
        }

        // Blocked users cannot log in — they must wait for their block window to expire.
        if ($user->blocked_until && now()->lessThan($user->blocked_until)) {
            return response()->json([
                'message' => 'Your account is temporarily blocked. Try again later.',
                'blocked_until' => $user->blocked_until,
            ], 403);
        }

        $token = $user->createToken('api_token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token
        ]);
    }

    // Revoke only the token used in this request so other devices stay logged in.
    // The client should discard the token from storage after calling this.
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    // Return the authenticated user's profile along with their agency (if they own one).
    // Useful for the frontend to know the user's role and agency status on load.
    public function me(Request $request)
    {
        $user = $request->user();

        // Load the agency relationship so agency_owners get their agency data in one call.
        if ($user->role === 'agency_owner') {
            $user->load('agency.city');
        }

        return response()->json(['user' => $user]);
    }
}
