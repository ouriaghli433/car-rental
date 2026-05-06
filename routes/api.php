<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AgencyController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CarController;
use App\Http\Controllers\CityController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

// =============================================================================
// PUBLIC AUTH ROUTES
// These two routes must stay outside Sanctum middleware so users can get a token.
// Rate-limit login to 10 attempts per minute to prevent brute-force attacks.
// =============================================================================
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login'])->middleware('throttle:10,1');

// =============================================================================
// PUBLIC BROWSING ROUTES (no token required)
// Clients and guests can search cars and browse agencies before signing in.
// =============================================================================

// City list — used to populate the city filter dropdown on the car search page
Route::get('/cities', [CityController::class, 'index']);

// Car catalog — supports filters: city_id, type, transmission, min_price, max_price, start_date, end_date
Route::get('/cars',              [CarController::class, 'index']);
Route::get('/cars/{id}',         [CarController::class, 'show']);
Route::get('/cars/{id}/reviews', [ReviewController::class, 'forCar']);

// Agency directory — only approved agencies appear in the public listing
Route::get('/agencies',              [AgencyController::class, 'index']);
Route::get('/agencies/{id}',         [AgencyController::class, 'show']);
Route::get('/agencies/{id}/reviews', [ReviewController::class, 'forAgency']);

// =============================================================================
// PROTECTED ROUTES (valid Sanctum bearer token required for all routes below)
// =============================================================================
Route::middleware('auth:sanctum')->group(function () {

    // -------------------------------------------------------------------------
    // AUTH EXTRAS
    // -------------------------------------------------------------------------
    Route::post('/logout', [AuthController::class, 'logout']); // revokes the current token only
    Route::get('/me',      [AuthController::class, 'me']);     // returns user profile + agency if owner

    // -------------------------------------------------------------------------
    // RESERVATIONS
    // Clients create reservations; agency owners confirm/cancel; admins see all.
    // -------------------------------------------------------------------------
    Route::get('/reservations',               [ReservationController::class, 'index']);
    Route::post('/reservations',              [ReservationController::class, 'store']);
    Route::get('/reservations/{id}',          [ReservationController::class, 'show']);
    Route::post('/reservations/{id}/confirm', [ReservationController::class, 'confirm']);
    Route::post('/reservations/{id}/cancel',  [ReservationController::class, 'cancel']);
    // Client confirms they physically collected the car — trip officially starts.
    Route::post('/reservations/{id}/pickup',  [ReservationController::class, 'pickup']);

    // Clients post a review after a reservation is completed (one review per reservation).
    Route::post('/reservations/{id}/review',  [ReviewController::class, 'store']);

    // -------------------------------------------------------------------------
    // PAYMENTS
    // Admins see all payments; agency owners see only their agency's payments.
    // -------------------------------------------------------------------------
    Route::get('/payments', [PaymentController::class, 'index']);

    // -------------------------------------------------------------------------
    // CAR MANAGEMENT (agency owners only, enforced in CarController + CarPolicy)
    // -------------------------------------------------------------------------
    Route::post('/cars',        [CarController::class, 'store']);   // create a car under the owner's agency
    Route::put('/cars/{id}',    [CarController::class, 'update']);  // update car details
    Route::delete('/cars/{id}', [CarController::class, 'destroy']); // soft-delete (blocked if active reservations exist)

    // Car image management
    Route::post('/cars/{id}/images',               [CarController::class, 'addImage']);    // add an image (is_primary demotes others)
    Route::delete('/cars/{id}/images/{imageId}',   [CarController::class, 'removeImage']); // remove a specific image

    // Car maintenance scheduling
    Route::post('/cars/{id}/maintenance',             [CarController::class, 'addMaintenance']);    // schedule a maintenance window
    Route::delete('/cars/{id}/maintenance/{periodId}',[CarController::class, 'removeMaintenance']); // cancel a maintenance window

    // -------------------------------------------------------------------------
    // AGENCY MANAGEMENT (agency owners only, enforced in AgencyController + AgencyPolicy)
    // -------------------------------------------------------------------------
    Route::post('/agencies',     [AgencyController::class, 'store']);  // register a new agency (starts as pending)
    Route::put('/agencies/{id}', [AgencyController::class, 'update']); // update agency profile

    // -------------------------------------------------------------------------
    // ADMIN ROUTES (admin role guard is checked inside AdminController::requireAdmin)
    // -------------------------------------------------------------------------

    // User management
    Route::get('/admin/users',               [AdminController::class, 'users']);            // list all users (paginated)
    Route::patch('/admin/users/{id}/status', [AdminController::class, 'updateUserStatus']); // block / activate a user

    // Agency management
    Route::get('/admin/agencies',                       [AdminController::class, 'agencies']);             // list all agencies (any status)
    Route::patch('/admin/agencies/{id}/status',         [AdminController::class, 'updateAgencyStatus']);   // approve / reject an agency
    Route::post('/admin/agencies/{id}/top-up',          [AdminController::class, 'topUpBalance']);         // add funds to agency balance
    Route::post('/admin/agencies/{id}/approve-changes', [AdminController::class, 'approveAgencyChanges']); // apply pending profile update
    Route::post('/admin/agencies/{id}/reject-changes',  [AdminController::class, 'rejectAgencyChanges']);  // discard pending profile update

    // Platform statistics
    Route::get('/admin/stats', [AdminController::class, 'stats']); // user/agency/car/reservation counts + revenue
});
