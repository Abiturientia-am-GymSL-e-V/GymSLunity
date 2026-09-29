<?php

declare(strict_types=1);

use App\Bookings\BookingManager;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('bookings:charge-due', function (BookingManager $manager): void {
    $this->info($manager->chargeDue().' fällige Buchungen wurden dem Beitragskonto belastet.');
})->purpose('Fällige Entgelte bestätigter Serientermine sollstellen');

Schedule::command('security:prune')->dailyAt('03:30')->withoutOverlapping();
// The demo discards all data every night, backups would only collect visitor input.
Schedule::command('app:backup --prune')->dailyAt('02:30')->withoutOverlapping()->skip(fn () => config('demo.enabled'));
Schedule::command('demo:reset')->dailyAt((string) config('demo.reset_at'))->timezone((string) config('app.display_timezone'))
    ->when(fn () => config('demo.enabled'));
Schedule::command('bookings:charge-due')->everyFifteenMinutes()->withoutOverlapping();
