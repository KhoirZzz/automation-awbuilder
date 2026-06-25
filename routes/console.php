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

// Sync and refresh Hermes auth token to shared cache
Artisan::command('hermes:sync-auth', function (\App\Services\HermesService $hermesService) {
    $home = env('HOME') ?? getenv('HOME') ?? '/home/awbuilder';
    $hermesBin = rtrim($home, '/') . '/.local/bin/hermes';
    
    if (file_exists($hermesBin)) {
        \Illuminate\Support\Facades\Process::run([$hermesBin, 'auth', 'list']);
    } else {
        \Illuminate\Support\Facades\Process::run(['hermes', 'auth', 'list']);
    }

    $creds = $hermesService->getResolvedCredentials();

    if ($creds && !empty($creds['key']) && $creds['key'] !== 'NOUS_PORTAL') {
        \Illuminate\Support\Facades\Cache::put('hermes_shared_credentials', $creds, 600);
        $this->info('Hermes credentials synced to cache successfully.');
    } else {
        $this->error('Failed to sync Hermes credentials.');
    }
})->purpose('Sync and refresh Hermes auth token');

Schedule::command('hermes:sync-auth')->everyFiveMinutes();

