<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymentProofJob;
use App\Services\TelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * PaymentBotController
 *
 * Receives webhook updates from the admin Telegram bot.
 * When a buyer sends their payment proof (photo/image) to the admin bot,
 * this controller dispatches ProcessPaymentProofJob to verify it via LLM.
 *
 * Webhook URL: POST /webhook/payment-bot
 * Set via: https://api.telegram.org/bot{TOKEN}/setWebhook?url=https://mockbuild.shop/api/webhook/payment-bot
 */
class PaymentBotController extends Controller
{
    public function __construct(private readonly TelegramBotService $adminBot)
    {
    }

    /**
     * Handle incoming Telegram webhook update.
     */
    public function handle(Request $request): JsonResponse
    {
        $update = $request->all();

        Log::channel('deploy-audit')->info('[PaymentBot] Incoming update', [
            'update_id' => $update['update_id'] ?? null,
        ]);

        // ── Deduplicate by update_id ──────────────────────────────────────────
        $updateId  = $update['update_id'] ?? null;
        $cacheKey  = "payment_bot_update_{$updateId}";
        if ($updateId && Cache::has($cacheKey)) {
            return response()->json(['status' => 'duplicate']);
        }
        if ($updateId) {
            Cache::put($cacheKey, true, now()->addMinutes(10));
        }

        $message   = $update['message'] ?? $update['channel_post'] ?? null;
        if (!$message) {
            return response()->json(['status' => 'no_message']);
        }

        $senderChatId = (string) ($message['chat']['id'] ?? '');
        $messageText  = $message['text'] ?? $message['caption'] ?? '';
        $messageId    = (int) ($message['message_id'] ?? 0);

        // ── Handle /start command ─────────────────────────────────────────────
        if (str_starts_with(trim($messageText), '/start')) {
            $this->adminBot->sendMessage(
                $senderChatId,
                "👋 <b>Selamat datang di AWBuilder Payment Bot!</b>\n\n"
                . "Untuk memverifikasi pembayaran Anda:\n\n"
                . "1️⃣ Kirim <b>screenshot/foto bukti transfer</b> ke chat ini\n"
                . "2️⃣ Sertakan <b>subdomain</b> Anda di caption foto\n"
                . "   Contoh caption: <code>toko-saya</code>\n\n"
                . "Bot akan otomatis memverifikasi dan mengaktifkan instansi Anda.\n\n"
                . "❓ Butuh bantuan? Hubungi: @awbuilderadmin",
            );
            return response()->json(['status' => 'ok']);
        }

        // ── Handle photo (payment proof) ──────────────────────────────────────
        $photos = $message['photo'] ?? null;
        $document = $message['document'] ?? null;

        if ($photos) {
            // Telegram sends multiple sizes; take the highest quality (last element)
            $bestPhoto = end($photos);
            $fileId    = $bestPhoto['file_id'] ?? null;

            if (!$fileId) {
                Log::channel('deploy-audit')->warning('[PaymentBot] Photo received but no file_id');
                return response()->json(['status' => 'error_no_file_id']);
            }

            Log::channel('deploy-audit')->info('[PaymentBot] Payment proof photo received', [
                'file_id'       => $fileId,
                'sender_chat_id'=> $senderChatId,
                'caption'       => $messageText,
            ]);

            // Acknowledge receipt immediately
            $this->adminBot->sendMessage(
                $senderChatId,
                "🔍 <b>Bukti bayar diterima!</b>\n\n"
                . "Sistem sedang memverifikasi nominal pembayaran Anda via AI...\n"
                . "⏳ Mohon tunggu 15-30 detik.",
            );

            // Also forward to admin with context
            $adminChatId = config('services.telegram.admin_chat_id');
            if ($adminChatId && $adminChatId !== $senderChatId) {
                $this->adminBot->sendMessage(
                    $adminChatId,
                    "📩 <b>Bukti bayar masuk dari buyer!</b>\n\n"
                    . "• Sender Chat ID: <code>{$senderChatId}</code>\n"
                    . "• Caption: <i>" . ($messageText ?: 'tidak ada') . "</i>\n\n"
                    . "Sistem sedang memproses verifikasi otomatis...",
                );
            }

            // Dispatch to queue
            ProcessPaymentProofJob::dispatch($fileId, $senderChatId, $messageText, $messageId);

            return response()->json(['status' => 'queued']);
        }

        // ── Handle document (PDF / image file) ───────────────────────────────
        if ($document) {
            $mimeType = $document['mime_type'] ?? '';
            $fileId   = $document['file_id'] ?? null;

            // Only process image documents
            if ($fileId && str_starts_with($mimeType, 'image/')) {
                Log::channel('deploy-audit')->info('[PaymentBot] Payment proof document (image) received', [
                    'file_id'   => $fileId,
                    'mime_type' => $mimeType,
                ]);

                $this->adminBot->sendMessage(
                    $senderChatId,
                    "🔍 <b>Dokumen bukti bayar diterima!</b>\n\nSistem sedang memverifikasi...",
                );

                ProcessPaymentProofJob::dispatch($fileId, $senderChatId, $messageText, $messageId);

                return response()->json(['status' => 'queued']);
            }
        }

        // ── Handle text messages (help/status inquiry) ────────────────────────
        if (!empty($messageText)) {
            $this->adminBot->sendMessage(
                $senderChatId,
                "📸 Silakan kirim <b>foto/screenshot bukti pembayaran</b> Anda.\n\n"
                . "Sertakan subdomain Anda di caption gambar.\n"
                . "Contoh: <code>toko-saya</code>",
            );
        }

        return response()->json(['status' => 'ok']);
    }
}
