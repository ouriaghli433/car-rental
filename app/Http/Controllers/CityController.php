<?php

namespace App\Http\Controllers;

use App\Models\City;

class CityController extends Controller
{
    // -------------------------------------------------------------------------
    // PUBLIC: List all active cities
    // Used by clients to populate the city filter dropdown on the car search page.
    // Only active cities are returned — inactive ones have no approved agencies.
    // -------------------------------------------------------------------------
    public function index()
    {
        $cities = City::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'region', 'country']);

        return response()->json(['data' => $cities]);
    }
}
