<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessLeadJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class LeadWebhookController extends Controller
{
    /**
     * Handle Telegram webhook.
     */
    public function telegram(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $messageText = $request->input('message.text');
        $messageId = $request->input('message.message_id');
        $chatId = $request->input('message.chat.id');

        if (empty($messageText) || empty($messageId)) {
            return response()->json(['status' => 'ignored', 'reason' => 'Invalid or empty message text.'], 200);
        }

        $leadReference = 'tg_' . $chatId . '_' . $messageId;

        // Dedup: Check lead_reference in cache with a 5-minute TTL
        if (!Cache::add('lead_dedup_' . $leadReference, true, 300)) {
            Log::channel('deploy-audit')->info('Duplicate Telegram webhook ignored.', [
                'lead_reference' => $leadReference
            ]);
            return response()->json(['status' => 'received', 'duplicate' => true]);
        }

        Log::channel('deploy-audit')->info('Received Telegram webhook.', [
            'lead_reference' => $leadReference,
            'message' => $messageText
        ]);

        ProcessLeadJob::dispatch($messageText, 'telegram', $leadReference);

        return response()->json(['status' => 'received']);
    }

    /**
     * Handle WhatsApp webhook.
     */
    public function whatsapp(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        // WhatsApp webhook verification challenge (GET request)
        if ($request->isMethod('GET') && $request->has('hub_mode') && $request->has('hub_challenge')) {
            $verifyToken = env('WHATSAPP_VERIFY_TOKEN');
            if ($request->input('hub_verify_token') === $verifyToken) {
                return response($request->input('hub_challenge'), 200)
                    ->header('Content-Type', 'text/plain');
            }
            return response()->json(['error' => 'Invalid verification token.'], 403);
        }

        $messageText = $request->input('entry.0.changes.0.value.messages.0.text.body');
        $messageId = $request->input('entry.0.changes.0.value.messages.0.id');

        if (empty($messageText) || empty($messageId)) {
            return response()->json(['status' => 'ignored', 'reason' => 'Invalid or empty message body.'], 200);
        }

        $leadReference = 'wa_' . $messageId;

        // Dedup: Check lead_reference in cache with a 5-minute TTL
        if (!Cache::add('lead_dedup_' . $leadReference, true, 300)) {
            Log::channel('deploy-audit')->info('Duplicate WhatsApp webhook ignored.', [
                'lead_reference' => $leadReference
            ]);
            return response()->json(['status' => 'received', 'duplicate' => true]);
        }

        Log::channel('deploy-audit')->info('Received WhatsApp webhook.', [
            'lead_reference' => $leadReference,
            'message' => $messageText
        ]);

        ProcessLeadJob::dispatch($messageText, 'whatsapp', $leadReference);

        return response()->json(['status' => 'received']);
    }
}
