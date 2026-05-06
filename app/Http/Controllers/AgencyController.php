<?php

namespace App\Http\Controllers;

use App\Http\Resources\AgencyResource;
use App\Models\Agency;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AgencyController extends Controller
{
    // -------------------------------------------------------------------------
    // PUBLIC: List all approved agencies with their city and car count
    // Anyone can browse agencies — no auth required.
    // -------------------------------------------------------------------------
    public function index()
    {
        // withCount('cars') adds a cars_count attribute used by AgencyResource.
        $agencies = Agency::with('city')
            ->withCount('cars')
            ->where('status', 'approved')
            ->latest()
            ->paginate(15);

        return AgencyResource::collection($agencies);
    }

    // -------------------------------------------------------------------------
    // PUBLIC: Show a single agency with its available cars and reviews
    // -------------------------------------------------------------------------
    public function show($id)
    {
        // Load cars (available only), reviews chain, and city for the detail view.
        $agency = Agency::with([
            'city',
            // Only show available cars on the public agency page.
            'cars' => fn($q) => $q->where('status', 'available')->with('images'),
            // Reviews need the reservation→client chain for the reviewer's name.
            'reviews.reservation.client',
        ])
        ->withCount('cars')
        ->findOrFail($id);

        return new AgencyResource($agency);
    }

    // -------------------------------------------------------------------------
    // AGENCY OWNER: Register a new agency
    // Each owner can only have one agency. Status starts as 'pending' until admin approves.
    // -------------------------------------------------------------------------
    public function store(Request $request)
    {
        $user = auth()->user();

        // Only users with the agency_owner role can create an agency profile.
        if ($user->role !== 'agency_owner') {
            return response()->json(['message' => 'Only agency owners can create an agency'], 403);
        }

        // Prevent duplicate agencies — one owner, one agency.
        if ($user->agency) {
            return response()->json(['message' => 'You already have an agency'], 400);
        }

        $data = $request->validate([
            'name'        => 'required|string|max:150|unique:agencies,name',
            // Validate that the city exists AND is currently active (not decommissioned).
            'city_id'     => 'required|exists:cities,id',
            'address'     => 'required|string|max:255',
            'phone'       => 'required|string|max:30',
            'email'       => 'required|email|unique:agencies,email',
            'description' => 'nullable|string',
            'logo_url'    => 'nullable|url',
        ]);

        // Reject registrations in deactivated cities — no approved agencies operate there.
        if (!\App\Models\City::where('id', $data['city_id'])->where('is_active', true)->exists()) {
            return response()->json(['message' => 'The selected city is not currently active'], 422);
        }

        // Generate a URL-safe slug from the agency name for public-facing URLs.
        $slug = Str::slug($data['name']);

        // If the slug is already taken, append a short unique suffix to keep it unique.
        if (Agency::where('slug', $slug)->exists()) {
            $slug .= '-' . Str::random(5);
        }

        $agency = Agency::create(array_merge($data, [
            'id'       => Str::uuid(),
            'owner_id' => $user->id,
            'slug'     => $slug,
            // New agencies start pending and cannot receive reservations until approved.
            'status'   => 'pending',
            'balance'  => 0,
        ]));

        return response()->json([
            'message' => 'Agency created and pending admin approval',
            'data'    => new AgencyResource($agency->load('city')),
        ], 201);
    }

    // -------------------------------------------------------------------------
    // AGENCY OWNER: Submit an update request for their agency profile.
    // Changes are NOT applied immediately — they are stored as pending_changes
    // and go live only after an admin approves them via the admin panel.
    // This prevents unreviewed changes (e.g. fake address) from going public.
    // -------------------------------------------------------------------------
    public function update(Request $request, $id)
    {
        $agency = Agency::findOrFail($id);

        // AgencyPolicy confirms the authenticated user is the owner of this agency.
        $this->authorize('update', $agency);

        $data = $request->validate([
            'name'        => 'sometimes|string|max:150|unique:agencies,name,' . $agency->id,
            'city_id'     => 'sometimes|exists:cities,id',
            'address'     => 'sometimes|string|max:255',
            'phone'       => 'sometimes|string|max:30',
            'email'       => 'sometimes|email|unique:agencies,email,' . $agency->id,
            'description' => 'nullable|string',
            'logo_url'    => 'nullable|url',
        ]);

        // Reject changes that point to a deactivated city.
        if (isset($data['city_id'])) {
            if (!\App\Models\City::where('id', $data['city_id'])->where('is_active', true)->exists()) {
                return response()->json(['message' => 'The selected city is not currently active'], 422);
            }
        }

        // Pre-compute the slug so the admin sees exactly what the final state will look like.
        if (isset($data['name']) && $data['name'] !== $agency->name) {
            $slug = Str::slug($data['name']);
            if (Agency::where('slug', $slug)->where('id', '!=', $agency->id)->exists()) {
                $slug .= '-' . Str::random(5);
            }
            $data['slug'] = $slug;
        }

        // Overwrite any previous pending request — only one update can be in review at a time.
        $agency->update(['pending_changes' => $data]);

        return response()->json([
            'message' => 'Update submitted and pending admin approval. Your current profile remains live until approved.',
            'data'    => new AgencyResource($agency->load('city')),
        ]);
    }
}
