<?php

use Illuminate\Support\Facades\File;

// Handle wildcard subdomains dynamically
Route::group(['domain' => '{subdomain}.' . parse_url(env('APP_URL', 'https://mockbuild.shop'), PHP_URL_HOST)], function () {
    Route::get('/{any?}', function ($subdomain, $any = null) {
        $reserved = ['www', 'admin', 'api', 'mail', 'app', 'dev', 'status', 'portal', 'dashboard'];
        if (in_array(strtolower($subdomain), $reserved)) {
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

Route::get('/{any?}', function () {
    return view('welcome');
})->where('any', '.*');
