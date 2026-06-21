<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

// Handle routing domain detection
$routeDomain = parse_url(config('app.url'), PHP_URL_HOST);
if (app()->runningUnitTests()) {
    $routeDomain = 'mockbuild.shop';
}

// 1. Admin & Dashboard panel subdomains
Route::group(['domain' => 'admin.' . $routeDomain], function () {
    Route::get('/{any?}', function () {
        return view('welcome');
    })->where('any', '(?!api/).*');
});

Route::group(['domain' => 'dashboard.' . $routeDomain], function () {
    Route::get('/{any?}', function () {
        return view('welcome');
    })->where('any', '(?!api/).*');
});

// 2. Wildcard client subdomains dynamically proxied
Route::group(['domain' => '{subdomain}.' . $routeDomain], function () {
    Route::get('/{any?}', function ($subdomain, $any = null) {
        $reserved = ['www', 'admin', 'api', 'mail', 'app', 'dev', 'status', 'portal', 'dashboard'];
        if (in_array(strtolower($subdomain), $reserved)) {
            if (in_array(strtolower($subdomain), ['admin', 'dashboard'])) {
                return view('welcome'); // Admin Panel SPA
            }
            if (strtolower($subdomain) === 'www') {
                return view('landing'); // Public Store landing page
            }
            abort(404);
        }

        $deployment = \App\Models\Deployment::where('client_slug', $subdomain)
            ->where('status', \App\Enums\DeploymentStatus::ACTIVE)
            ->first();

        if (!$deployment) {
            abort(404, "Deployment not found or inactive.");
        }

        $basePath = $deployment->instance_path;
        $any = $any ? ltrim($any, '/') : 'index.html';

        // Check direct path
        $filePath = $basePath . '/' . $any;
        if (!File::exists($filePath)) {
            // Check inside subdirectories (handles nested folders in zip like agoda.com/)
            $directories = File::directories($basePath);
            foreach ($directories as $dir) {
                if (File::exists($dir . '/' . $any)) {
                    $filePath = $dir . '/' . $any;
                    break;
                }
            }
        }

        // If file still not found and we are looking for a directory/route, fallback to index.html
        if (!File::exists($filePath) || File::isDirectory($filePath)) {
            $filePath = $basePath . '/index.html';
            if (!File::exists($filePath)) {
                $directories = File::directories($basePath);
                foreach ($directories as $dir) {
                    if (File::exists($dir . '/index.html')) {
                        $filePath = $dir . '/index.html';
                        break;
                    }
                }
            }
        }

        if (!File::exists($filePath) || File::isDirectory($filePath)) {
            abort(404, "File not found inside instance.");
        }

        $mime = File::mimeType($filePath);
        if (str_ends_with($filePath, '.css')) {
            $mime = 'text/css';
        } elseif (str_ends_with($filePath, '.js')) {
            $mime = 'application/javascript';
        }

        return response(File::get($filePath), 200)
            ->header('Content-Type', $mime);
    })->where('any', '.*');
});

// 3. Fallback route: Main domain, WWW, and localhost serves the public landing store page
Route::get('/{any?}', function () {
    return view('landing');
})->where('any', '.*');
