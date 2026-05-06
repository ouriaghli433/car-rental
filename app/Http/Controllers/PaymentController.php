<?php

namespace App\Http\Controllers;

use App\Models\Payment;

class PaymentController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        // Admins can review every payment across the platform.
        if ($user->role === 'admin') {
            $payments = Payment::with('agency')->latest()->paginate(20);
        }
        // Agency owners can review only payments attached to their own agency.
        elseif ($user->role === 'agency_owner' && $user->agency) {
            $payments = Payment::with('agency')
                ->where('agency_id', $user->agency->id)
                ->latest()
                ->paginate(20);
        }
        // Clients do not own agency balances, so they cannot list agency payment records.
        else {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Return a compact paginated ledger response.
        return response()->json([
            'data' => $payments->map(function ($payment) {
                return [
                    'id'             => $payment->id,
                    'type'           => $payment->type,
                    'amount'         => $payment->amount,
                    'status'         => $payment->status,
                    'agency'         => $payment->agency?->name,
                    'reservation_id' => $payment->reservation_id,
                    'balance_before' => $payment->balance_before,
                    'balance_after'  => $payment->balance_after,
                    'created_at'     => $payment->created_at,
                ];
            }),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page'    => $payments->lastPage(),
                'per_page'     => $payments->perPage(),
                'total'        => $payments->total(),
            ],
        ]);
    }
}
