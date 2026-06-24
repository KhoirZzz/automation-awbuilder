<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Run audit of expired deployments daily
Schedule::command('deploy:audit-expired')->daily();
Schedule::command('deploy:send-expiry-reminders')->daily();
Schedule::command('deploy:clean-archive')->daily();
Schedule::command('deploy:monitor-resources')->hourly();
