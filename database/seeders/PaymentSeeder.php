<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PaymentSeeder extends Seeder
{
    public function run(): void
    {
        $payments = [
            [
                'reservation_status' => 'confirmed',
                'type' => 'commission',
                'status' => 'completed',
                'reference_prefix' => 'COM',
            ],
            [
                'reservation_status' => 'cancelled',
                'type' => 'commission',
                'status' => 'completed',
                'reference_prefix' => 'COM',
            ],
            [
                'reservation_status' => 'cancelled',
                'type' => 'refund',
                'status' => 'completed',
                'reference_prefix' => 'REF',
            ],
            [
                'reservation_status' => 'completed',
                'type' => 'commission',
                'status' => 'completed',
                'reference_prefix' => 'COM',
            ],
        ];

        foreach ($payments as $payment) {
            $reservation = Reservation::with('agency')
                ->where('status', $payment['reservation_status'])
                ->first();

            // Skip ledger rows if the matching reservation scenario is missing.
            if (!$reservation || !$reservation->agency) {
                continue;
            }

            $amount = $reservation->total_amount * 0.1;
            $reference = $payment['reference_prefix'] . '-' . $reservation->id;

            // Seed payments by reference so reruns update the same ledger row.
            Payment::updateOrCreate(
                ['reference' => $reference],
                [
                    'id' => Payment::where('reference', $reference)->value('id') ?? (string) Str::uuid(),
                    'reservation_id' => $reservation->id,
                    'agency_id' => $reservation->agency->id,
                    'amount' => $amount,
                    'type' => $payment['type'],
                    'status' => $payment['status'],
                    'balance_before' => $reservation->agency->balance,
                    'balance_after' => $payment['type'] === 'refund'
                        ? $reservation->agency->balance + $amount
                        : $reservation->agency->balance - $amount,
                ]
            );
        }

        foreach (Agency::where('status', 'approved')->get() as $agency) {
            // Add one top-up row per approved agency for payment filtering tests.
            Payment::updateOrCreate(
                ['reference' => 'TOP-' . $agency->slug],
                [
                    'id' => Payment::where('reference', 'TOP-' . $agency->slug)->value('id') ?? (string) Str::uuid(),
                    'reservation_id' => null,
                    'agency_id' => $agency->id,
                    'amount' => 250,
                    'type' => 'top_up',
                    'status' => 'completed',
                    'balance_before' => $agency->balance,
                    'balance_after' => $agency->balance + 250,
                ]
            );
        }

        $agency = Agency::where('slug', 'casa-drive')->first();

        if ($agency) {
            // Add a failed top-up row so failed payment states can be tested too.
            Payment::updateOrCreate(
                ['reference' => 'FAIL-' . $agency->slug],
                [
                    'id' => Payment::where('reference', 'FAIL-' . $agency->slug)->value('id') ?? (string) Str::uuid(),
                    'reservation_id' => null,
                    'agency_id' => $agency->id,
                    'amount' => 500,
                    'type' => 'top_up',
                    'status' => 'failed',
                    'balance_before' => $agency->balance,
                    'balance_after' => $agency->balance,
                ]
            );
        }
    }
}
