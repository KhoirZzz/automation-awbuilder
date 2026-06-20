<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyAdminPasskey
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $correctPasskey = config('deploy.agent_passkey', '852963');
        $headerPasskey = $request->header('X-Admin-Passkey');

        if (empty($correctPasskey) || $headerPasskey !== $correctPasskey) {
            return response()->json(['error' => 'Akses ditolak. Passkey admin tidak valid atau kosong.'], 401);
        }

        return $next($request);
    }
}
