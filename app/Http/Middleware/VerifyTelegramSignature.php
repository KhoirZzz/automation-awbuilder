<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyTelegramSignature
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('services.telegram.bot_secret_token');
        $headerToken = $request->header('X-Telegram-Bot-Api-Secret-Token');

        if (empty($token) || $headerToken !== $token) {
            return response()->json(['error' => 'Unauthorized Telegram request.'], 401);
        }

        return $next($request);
    }
}
