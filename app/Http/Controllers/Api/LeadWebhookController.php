<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessLeadJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

use App\Services\TelegramBotService;

class LeadWebhookController extends Controller
{
    /**
     * Handle Telegram webhook.
     */
    public function telegram(Request $request, TelegramBotService $botService): \Symfony\Component\HttpFoundation\Response
    {
        $messageText = trim($request->input('message.text', ''));
        $messageId = $request->input('message.message_id');
        $chatId = $request->input('message.chat.id');

        if (empty($messageText) || empty($messageId) || empty($chatId)) {
            return response()->json(['status' => 'ignored', 'reason' => 'Invalid or empty message.'], 200);
        }

        // 1. Handle `/start` command
        if ($messageText === '/start') {
            $botService->sendMessage($chatId, "<b>Halo! Selamat datang di AWBuilder Auto Buyer Bot.</b>\n\nUntuk melakukan order deployment aplikasi secara otomatis, silakan kirim pesan instruksi detail. Contoh:\n\n<i>\"Order gojek untuk subdomain tokoabc selama 1 bulan dengan token bot 12345:ABC dan chat id 987654\"</i>\n\nSistem kami akan memproses orderan Anda secara real-time!");
            return response()->json(['status' => 'received']);
        }

        if (preg_match('/^\/approve\s+(\S+)(?:\s+(\d+))?/i', $messageText, $matches)) {
            $adminChatId = env('TELEGRAM_ADMIN_CHAT_ID');
            if (empty($adminChatId) || (string)$chatId !== (string)$adminChatId) {
                $botService->sendMessage($chatId, "❌ Anda tidak memiliki otorisasi untuk melakukan tindakan ini.");
                return response()->json(['status' => 'received']);
            }

            $target = $matches[1];
            $priceInput = $matches[2] ?? null;
            $deployment = \App\Models\Deployment::where('client_slug', $target)
                ->orWhere('id', $target)
                ->first();

            if (!$deployment) {
                $botService->sendMessage($chatId, "❌ Deployment '<b>{$target}</b>' tidak ditemukan.");
                return response()->json(['status' => 'received']);
            }

            if ($deployment->status !== \App\Enums\DeploymentStatus::PENDING_PAYMENT) {
                $botService->sendMessage($chatId, "ℹ️ Status deployment '<b>{$target}</b>' saat ini adalah <b>{$deployment->status->value}</b> (bukan pending_payment).");
                return response()->json(['status' => 'received']);
            }

            // Update status and optional price
            $updateData = ['status' => \App\Enums\DeploymentStatus::ACTIVE];
            if ($priceInput !== null) {
                $updateData['price'] = (int)$priceInput;
            }
            $deployment->update($updateData);

            // Reconstruct final client URL
            $baseDomain = 'mockbuild.shop';
            $host = $request->getHost();
            if (!preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $host)) {
                $baseDomain = preg_replace('/^(admin|dashboard|www|api)\./i', '', $host);
            }
            $clientUrl = "http://{$deployment->client_slug}.{$baseDomain}";

            // Notify client if chat ID is available in lead_reference
            if (str_starts_with($deployment->lead_reference, 'tg_')) {
                $parts = explode('_', $deployment->lead_reference);
                $clientChatId = $parts[1] ?? null;

                if ($clientChatId) {
                    $botService->sendMessage($clientChatId, "✅ <b>PEMBAYARAN DITERIMA!</b>\n\nTerima kasih, pembayaran Anda telah diverifikasi oleh Admin.\nAplikasi Anda telah aktif sepenuhnya.\n\n<b>Link Tautan:</b> <a href=\"{$clientUrl}\">{$clientUrl}</a>");
                }
            }

            $botService->sendMessage($chatId, "✅ Sukses menyetujui pembayaran untuk <b>{$deployment->client_slug}</b>. Link aktif telah dikirimkan ke client.");
            return response()->json(['status' => 'received']);
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
