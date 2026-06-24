<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deployment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientApiController extends Controller
{
    /**
     * Check status of a client's deployment.
     */
    public function status(Request $request): JsonResponse
    {
        $token = $request->header('X-Client-Token') ?? $request->query('token');

        if (empty($token)) {
            return response()->json([
                'error' => 'Unauthorized.',
                'message' => 'Please provide a valid token in the X-Client-Token header or token query parameter.'
            ], 401);
        }

        $deployment = Deployment::with('serviceTemplate')
            ->where('client_token', $token)
            ->first();

        if (!$deployment) {
            return response()->json([
                'error' => 'Unauthorized.',
                'message' => 'Invalid or expired client token.'
            ], 401);
        }

        // Reconstruct base domain URL
        $baseDomain = 'mockbuild.shop';
        $host = $request->getHost();
        if (!preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $host)) {
            $baseDomain = preg_replace('/^(admin|dashboard|www|api)\./i', '', $host);
        }
        
        $url = "http://{$deployment->client_slug}.{$baseDomain}";
        if ($deployment->custom_domain) {
            $url = "http://{$deployment->custom_domain}";
        }

        return response()->json([
            'client_slug' => $deployment->client_slug,
            'status' => $deployment->status->value,
            'started_at' => $deployment->started_at ? $deployment->started_at->toIso8601String() : null,
            'expires_at' => $deployment->expires_at ? $deployment->expires_at->toIso8601String() : null,
            'price' => $deployment->price,
            'service' => $deployment->serviceTemplate ? [
                'key' => $deployment->serviceTemplate->key,
                'name' => $deployment->serviceTemplate->name,
                'category' => $deployment->serviceTemplate->category,
                'version' => $deployment->serviceTemplate->version,
            ] : null,
            'url' => $url,
            'custom_domain' => $deployment->custom_domain,
            'resource_usage' => [
                'cpu_usage_pct' => $deployment->cpu_usage,
                'ram_usage_mb' => $deployment->ram_usage,
                'disk_usage_mb' => $deployment->disk_usage,
                'last_monitored_at' => $deployment->last_monitored_at ? $deployment->last_monitored_at->toIso8601String() : null,
            ]
        ]);
    }
}
