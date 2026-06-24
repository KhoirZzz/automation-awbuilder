<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Deployment;
use App\Enums\DeploymentStatus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class ServeCustomDomain
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $routeDomain = parse_url(config('app.url'), PHP_URL_HOST);
        if (app()->runningUnitTests()) {
            $routeDomain = 'mockbuild.shop';
        }

        // Avoid intercepting local dev servers, main domain, or subdomain wildcard domains
        if ($host !== 'localhost' && $host !== '127.0.0.1' && $host !== $routeDomain && !str_ends_with($host, '.' . $routeDomain)) {
            try {
                // Verify schema is migrated to prevent DB connection exceptions during unrelated tests
                if (Schema::hasTable('deployments')) {
                    $deployment = Deployment::where('custom_domain', $host)
                        ->where('status', DeploymentStatus::ACTIVE)
                        ->first();

                    if ($deployment) {
                        $basePath = $deployment->instance_path;
                        $path = ltrim($request->getPathInfo(), '/');
                        if (empty($path) || $path === 'index.php') {
                            $path = 'index.html';
                        }

                        if (str_starts_with($path, 'api/')) {
                            return $next($request);
                        }

                        $filePath = $basePath . '/' . $path;
                        if (!File::exists($filePath)) {
                            $directories = File::directories($basePath);
                            foreach ($directories as $dir) {
                                if (File::exists($dir . '/' . $path)) {
                                    $filePath = $dir . '/' . $path;
                                    break;
                                }
                            }
                        }

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
                    }
                }
            } catch (\Throwable $th) {
                // Silently fall back to next handler on any database exceptions during boots
            }
        }

        return $next($request);
    }
}
