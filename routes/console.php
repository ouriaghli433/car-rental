<?php

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('reservations:expire', function () {
    // Cancel pending reservations whose one-hour confirmation window has passed.
    $count = Reservation::where('status', 'pending')
        ->whereNotNull('expires_at')
        ->where('expires_at', '<=', now())
        ->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => null,
            'cancellation_reason' => 'Reservation expired before agency confirmation.',
        ]);

    $this->info("Expired {$count} pending reservation(s).");
})->purpose('Cancel pending reservations that were not confirmed within one hour');

Artisan::command('reservations:complete', function () {
    // Mark confirmed reservations as completed once their end_date has passed.
    // Requires picked_up_at to be set — if the client never collected the car,
    // the trip never happened and the reservation should not be reviewable.
    $count = Reservation::where('status', 'confirmed')
        ->whereNotNull('picked_up_at')
        ->whereDate('end_date', '<', today())
        ->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

    $this->info("Completed {$count} reservation(s).");
})->purpose('Mark confirmed reservations as completed after their end date passes');

Artisan::command('users:reset-cancel-counts', function () {
    // Reset the daily cancellation counter for all users at midnight.
    // Without this, clients who hit the 2/day limit would stay blocked on the counter forever.
    $count = User::where('cancel_count_today', '>', 0)
        ->update(['cancel_count_today' => 0]);

    $this->info("Reset cancel counter for {$count} user(s).");
})->purpose('Reset daily cancellation counters for all users at midnight');

// Expire unconfirmed reservations every minute.
Schedule::command('reservations:expire')->everyMinute();

// Auto-complete confirmed reservations whose rental period has ended — runs daily at 01:00.
Schedule::command('reservations:complete')->dailyAt('01:00');

// Reset each client's daily cancellation counter at midnight.
Schedule::command('users:reset-cancel-counts')->dailyAt('00:00');
