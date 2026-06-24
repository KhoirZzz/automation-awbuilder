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
    Route::any('/{any?}', function ($subdomain, $any = null) {
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

        // Execute PHP file and buffer response output
        if (str_ends_with($filePath, '.php')) {
            ob_start();
            // Reset status code sebelum include agar kita bisa baca yang di-set script
            http_response_code(200);
            try {
                $oldCwd = getcwd();
                chdir(dirname($filePath));

                include $filePath;

                chdir($oldCwd);
                $output = ob_get_clean();

                $statusCode = http_response_code();
                if ($statusCode === false) {
                    $statusCode = 200;
                }

                $response = response($output, $statusCode);

                // Set headers set by the PHP script
                foreach (headers_list() as $header) {
                    if (strpos($header, ':') !== false) {
                        [$name, $value] = explode(':', $header, 2);
                        $name = trim($name);
                        // Skip session/cookie headers from the included script
                        if (in_array(strtolower($name), ['set-cookie', 'x-powered-by'])) continue;
                        $response->header($name, trim($value));
                    }
                }

                return $response;
            } catch (\Throwable $e) {
                ob_end_clean();
                return response("PHP Execution Error: " . $e->getMessage(), 500);
            }
        }

        $mime = File::mimeType($filePath);
        if (str_ends_with($filePath, '.css')) {
            $mime = 'text/css';
        } elseif (str_ends_with($filePath, '.js')) {
            $mime = 'application/javascript';
        } elseif (str_ends_with($filePath, '.html') || str_ends_with($filePath, '.htm')) {
            $mime = 'text/html';
        }

        return response(File::get($filePath), 200)
            ->header('Content-Type', $mime);
    })->where('any', '(?!api/).*');
});

// 2.5 Public template preview route
Route::any('/templates/{key}/preview/{any?}', function ($key, $any = null) {
    $template = \App\Models\ServiceTemplate::where('key', $key)->first();
    if (!$template) {
        abort(404, "Template not found.");
    }

    $basePath = config('deploy.template_base_path') . '/' . $template->template_path;
    $any = $any ? ltrim($any, '/') : 'index.html';

    $filePath = $basePath . '/' . $any;
    if (!\Illuminate\Support\Facades\File::exists($filePath)) {
        $directories = \Illuminate\Support\Facades\File::directories($basePath);
        foreach ($directories as $dir) {
            if (\Illuminate\Support\Facades\File::exists($dir . '/' . $any)) {
                $filePath = $dir . '/' . $any;
                break;
            }
        }
    }

    if (!\Illuminate\Support\Facades\File::exists($filePath) || \Illuminate\Support\Facades\File::isDirectory($filePath)) {
        $filePath = $basePath . '/index.html';
        if (!\Illuminate\Support\Facades\File::exists($filePath)) {
            $directories = \Illuminate\Support\Facades\File::directories($basePath);
            foreach ($directories as $dir) {
                if (\Illuminate\Support\Facades\File::exists($dir . '/index.html')) {
                    $filePath = $dir . '/index.html';
                    break;
                }
            }
        }
    }

    if (!\Illuminate\Support\Facades\File::exists($filePath) || \Illuminate\Support\Facades\File::isDirectory($filePath)) {
        abort(404, "Preview file not found.");
    }

    if (str_ends_with($filePath, '.php')) {
        ob_start();
        http_response_code(200);
        try {
            $oldCwd = getcwd();
            chdir(dirname($filePath));
            include $filePath;
            chdir($oldCwd);
            $output = ob_get_clean();

            $statusCode = http_response_code();
            if ($statusCode === false) {
                $statusCode = 200;
            }

            $response = response($output, $statusCode);

            foreach (headers_list() as $header) {
                if (strpos($header, ':') !== false) {
                    [$name, $value] = explode(':', $header, 2);
                    $name = trim($name);
                    if (in_array(strtolower($name), ['set-cookie', 'x-powered-by'])) continue;
                    $response->header($name, trim($value));
                }
            }

            return $response;
        } catch (\Throwable $e) {
            ob_end_clean();
            return response("PHP Preview Error: " . $e->getMessage(), 500);
        }
    }

    $mime = \Illuminate\Support\Facades\File::mimeType($filePath);
    if (str_ends_with($filePath, '.css')) {
        $mime = 'text/css';
    } elseif (str_ends_with($filePath, '.js')) {
        $mime = 'application/javascript';
    } elseif (str_ends_with($filePath, '.html') || str_ends_with($filePath, '.htm')) {
        $mime = 'text/html';
    }

    return response(\Illuminate\Support\Facades\File::get($filePath), 200)
        ->header('Content-Type', $mime);
})->where('any', '.*');

// 3. Dynamic PDF download route by slug on the main domain/WWW
Route::get('/{slug}', function ($slug) {
    // Look up deployment by slug
    $deployment = \App\Models\Deployment::where('client_slug', $slug)->first();

    if ($deployment) {
        $isAdmin = request()->query('passkey') === config('deploy.agent_passkey');

        if ($deployment->status === \App\Enums\DeploymentStatus::ACTIVE || $isAdmin) {
            $basePath = $deployment->instance_path;
            
            // Check for PDF files
            if (\Illuminate\Support\Facades\File::isDirectory($basePath)) {
                $pdfs = glob($basePath . "/*.pdf");
                if (!empty($pdfs)) {
                    $filePath = $pdfs[0];
                    if (file_exists($filePath)) {
                        $mime = \Illuminate\Support\Facades\File::mimeType($filePath);
                        return response()->download($filePath, basename($filePath), [
                            'Content-Type' => $mime,
                        ]);
                    }
                }
            }
            return abort(404, "Berkas PDF tidak ditemukan di folder instance.");
        }

        // Return pending status page if not active
        return response()->view('pending_download', ['deployment' => $deployment]);
    }

    // Fallback to landing page if not found
    return view('landing');
})->where('slug', '^[a-z0-9](-?[a-z0-9])*$');

// 4. Fallback route: Main domain, WWW, and localhost serves the public landing store page
Route::get('/{any?}', function () {
    return view('landing');
})->where('any', '(?!api/).*');
