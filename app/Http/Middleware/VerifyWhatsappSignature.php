<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWhatsappSignature
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.whatsapp.app_secret');
        $header = $request->header('X-Hub-Signature-256');

        if (empty($secret) || empty($header)) {
            return response()->json(['error' => 'Unauthorized WhatsApp request.'], 401);
        }

        // Expected format: sha256=hash
        $expectedHash = $header;
        if (str_starts_with($header, 'sha256=')) {
            $expectedHash = substr($header, 7);
        }

        $calculatedHash = hash_hmac('sha256', $request->getContent(), $secret);

        if (!hash_equals($expectedHash, $calculatedHash)) {
            return response()->json(['error' => 'Invalid WhatsApp signature.'], 401);
        }

        return $next($request);
    }
}
