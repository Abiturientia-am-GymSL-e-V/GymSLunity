<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('security:prune')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('app:backup --prune')->dailyAt('02:30')->withoutOverlapping();
