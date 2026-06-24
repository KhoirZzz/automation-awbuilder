<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\WebhookRequest;
use Illuminate\Support\Facades\Log;

class LogWebhookRequest
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
        $ip = $request->ip();
        $source = str_contains($request->getPathInfo(), 'telegram') ? 'telegram' : 'whatsapp';
        $payload = $request->getContent();
        $payloadHash = hash('sha256', $payload ?: '');

        // Check if this payload hash has already been successfully processed
        // We only deduplicate successful ones to allow retrying if it failed earlier due to signature/rate limits
        $isDuplicate = WebhookRequest::where('payload_hash', $payloadHash)
            ->where('validation_status', 'success')
            ->exists();

        if ($isDuplicate) {
            Log::channel('deploy-audit')->info('Duplicate webhook payload detected (hash deduplication).', [
                'ip' => $ip,
                'source' => $source,
                'payload_hash' => $payloadHash,
            ]);

            // Save log record to DB
            WebhookRequest::create([
                'ip_address' => $ip,
                'source' => $source,
                'payload_hash' => $payloadHash,
                'payload' => $payload,
                'status_code' => 200,
                'validation_status' => 'duplicate',
            ]);

            return response()->json(['status' => 'received', 'duplicate' => true]);
        }

        // Execute subsequent middleware and handler
        $response = $next($request);

        // Determine validation status based on HTTP status code
        $statusCode = $response->getStatusCode();
        $validationStatus = 'success';
        if ($statusCode === 401 || $statusCode === 403) {
            $validationStatus = 'unauthorized';
        } elseif ($statusCode === 429) {
            $validationStatus = 'rate_limited';
        } elseif ($statusCode >= 400) {
            $validationStatus = 'invalid';
        }

        // Save log record to DB
        WebhookRequest::create([
            'ip_address' => $ip,
            'source' => $source,
            'payload_hash' => $payloadHash,
            'payload' => $payload,
            'status_code' => $statusCode,
            'validation_status' => $validationStatus,
        ]);

        Log::channel('deploy-audit')->info("Incoming webhook request details.", [
            'ip' => $ip,
            'source' => $source,
            'payload_hash' => $payloadHash,
            'status_code' => $statusCode,
            'validation_status' => $validationStatus,
            'timestamp' => now()->toIso8601String()
        ]);

        return $response;
    }
}
