<?php

namespace App\Http\Controllers;

use App\Http\Resources\ReviewResource;
use App\Models\Agency;
use App\Models\Car;
use App\Models\Reservation;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReviewController extends Controller
{
    // -------------------------------------------------------------------------
    // CLIENT: Post a review for a completed reservation
    // Only the client who made the reservation can review it, and only once.
    // -------------------------------------------------------------------------
    public function store(Request $request, $reservationId)
    {
        $user = auth()->user();

        // Only clients can write reviews — agency owners and admins cannot.
        if ($user->role !== 'client') {
            return response()->json(['message' => 'Only clients can post reviews'], 403);
        }

        // Load the reservation with car and agency so we can update their ratings after.
        $reservation = Reservation::with(['car', 'agency'])->findOrFail($reservationId);

        // Cast both sides to string — client_id comes from DB as a string while
        // $user->id may be a LazyUuidFromString object when freshly created.
        if ((string) $reservation->client_id !== (string) $user->id) {
            return response()->json(['message' => 'You can only review your own reservations'], 403);
        }

        // Only completed rentals can be reviewed — the experience must have happened.
        if ($reservation->status !== 'completed') {
            return response()->json(['message' => 'You can only review completed reservations'], 400);
        }

        // One review per reservation — prevent duplicate submissions.
        if ($reservation->review) {
            return response()->json(['message' => 'You have already reviewed this reservation'], 400);
        }

        $data = $request->validate([
            // Ratings must be whole numbers from 1 to 5.
            'car_rating'    => 'required|integer|min:1|max:5',
            'agency_rating' => 'required|integer|min:1|max:5',
            'comment'       => 'nullable|string|max:1000',
        ]);

        // Wrap everything in a transaction so the review insert and rating recalculations
        // always stay in sync — a partial failure rolls back all three changes.
        $review = DB::transaction(function () use ($data, $reservation) {
            $review = Review::create([
                'id'             => Str::uuid(),
                'reservation_id' => $reservation->id,
                'car_rating'     => $data['car_rating'],
                'agency_rating'  => $data['agency_rating'],
                'comment'        => $data['comment'] ?? null,
            ]);

            // Recalculate the car's average rating from all reviews linked through reservations.
            $car = $reservation->car;
            $carStats = Review::whereHas('reservation', fn($q) => $q->where('car_id', $car->id))
                ->selectRaw('AVG(car_rating) as avg, COUNT(*) as total')
                ->first();

            $car->update([
                'avg_rating'    => round($carStats->avg, 2),
                'total_reviews' => $carStats->total,
            ]);

            // Recalculate the agency's average rating from all reviews linked through reservations.
            $agency = $reservation->agency;
            $agencyStats = Review::whereHas('reservation', fn($q) => $q->where('agency_id', $agency->id))
                ->selectRaw('AVG(agency_rating) as avg, COUNT(*) as total')
                ->first();

            $agency->update([
                'avg_rating'    => round($agencyStats->avg, 2),
                'total_reviews' => $agencyStats->total,
            ]);

            return $review;
        });

        // Load the reservation→client chain so ReviewResource can include the reviewer's name.
        $review->load('reservation.client');

        return response()->json([
            'message' => 'Review submitted successfully',
            'data'    => new ReviewResource($review),
        ], 201);
    }

    // -------------------------------------------------------------------------
    // PUBLIC: List all reviews for a specific car (paginated)
    // -------------------------------------------------------------------------
    public function forCar($carId)
    {
        // Confirm the car exists before querying reviews.
        Car::findOrFail($carId);

        // Fetch reviews through the hasManyThrough reservation chain.
        // Load the reservation→client chain so ReviewResource can show the reviewer's name.
        $reviews = Review::with('reservation.client')
            ->whereHas('reservation', fn($q) => $q->where('car_id', $carId))
            ->latest()
            ->paginate(15);

        return ReviewResource::collection($reviews);
    }

    // -------------------------------------------------------------------------
    // PUBLIC: List all reviews for a specific agency (paginated)
    // -------------------------------------------------------------------------
    public function forAgency($agencyId)
    {
        // Confirm the agency exists before querying reviews.
        Agency::findOrFail($agencyId);

        // Fetch reviews through the hasManyThrough reservation chain.
        // Load the reservation→client chain so ReviewResource can show the reviewer's name.
        $reviews = Review::with('reservation.client')
            ->whereHas('reservation', fn($q) => $q->where('agency_id', $agencyId))
            ->latest()
            ->paginate(15);

        return ReviewResource::collection($reviews);
    }
}
