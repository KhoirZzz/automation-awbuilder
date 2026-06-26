<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymentProofJob;
use App\Services\TelegramBotService;
use App\Models\Deployment;
use App\Enums\DeploymentStatus;
use App\Actions\DeployServiceAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

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

        // ── Handle Callback Query (Approve button click) ──────────────────────
        if (isset($update['callback_query'])) {
            return $this->handleCallbackQuery($update['callback_query']);
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
                . "Bukti bayar Anda akan dikirim ke Admin untuk divalidasi secara manual.\n\n"
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
                . "Bukti transfer Anda sedang diteruskan ke Admin untuk divalidasi.\n"
                . "⏳ Mohon tunggu sebentar, Anda akan menerima notifikasi di sini setelah disetujui.",
            );

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
                    "🔍 <b>Dokumen bukti bayar diterima!</b>\n\n"
                    . "Bukti transfer Anda sedang diteruskan ke Admin untuk divalidasi.\n"
                    . "⏳ Mohon tunggu sebentar.",
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

    /**
     * Handle callback query when admin clicks the Approve inline button.
     */
    private function handleCallbackQuery(array $callbackQuery): JsonResponse
    {
        $callbackQueryId = $callbackQuery['id'];
        $data = $callbackQuery['data'] ?? '';
        $adminChatId = (string) ($callbackQuery['message']['chat']['id'] ?? '');
        $messageId = $callbackQuery['message']['message_id'] ?? null;
        $caption = $callbackQuery['message']['caption'] ?? $callbackQuery['message']['text'] ?? '';

        Log::channel('deploy-audit')->info('[PaymentBot] Callback query received', [
            'id' => $callbackQueryId,
            'data' => $data,
            'admin_chat_id' => $adminChatId,
        ]);

        if (str_starts_with($data, 'approve_dep:')) {
            $deploymentId = (int) str_replace('approve_dep:', '', $data);
            $deployment = Deployment::find($deploymentId);

            if (!$deployment) {
                $this->adminBot->answerCallbackQuery($callbackQueryId, 'Deployment tidak ditemukan!', true);
                return response()->json(['status' => 'error_not_found']);
            }

            if ($deployment->status === DeploymentStatus::ACTIVE->value) {
                $this->adminBot->answerCallbackQuery($callbackQueryId, 'Layanan ini sudah aktif!', false);
                return response()->json(['status' => 'already_active']);
            }

            try {
                // Activate the deployment
                $deployment->update([
                    'status'              => DeploymentStatus::ACTIVE->value,
                    'started_at'          => now(),
                    'payment_verified_at' => now(),
                ]);

                // Run activation action
                $deployAction = app(DeployServiceAction::class);
                $deployAction->activateExistingDeployment($deployment);

                // Notify admin with answerCallbackQuery popup
                $this->adminBot->answerCallbackQuery($callbackQueryId, 'Pembayaran disetujui & layanan aktif!', false);

                // Edit the original message to remove the keyboard and add approval status
                $clientUrl = "https://{$deployment->client_slug}.mockbuild.shop";
                $updatedCaption = $caption . "\n\n🟢 <b>STATUS: APPROVED</b> oleh Admin pada " . now()->format('d/m/Y H:i') . "\nURL: <a href=\"{$clientUrl}\">{$clientUrl}</a>";
                
                $this->adminBot->editMessageCaption($adminChatId, $messageId, $updatedCaption, []);

                // Notify buyer via their bot token
                $buyerChatId = $deployment->buyer_telegram_chat_id;
                $buyerToken  = $deployment->buyer_telegram_token;

                if ($buyerChatId && $buyerToken) {
                    $serviceTemplate = $deployment->serviceTemplate;
                    $serviceLabel    = $serviceTemplate?->name ?? 'Layanan';
                    $expiresAt       = $deployment->expires_at?->locale('id')->isoFormat('D MMMM Y') ?? '-';

                    $buyerMessage = "✅ <b>Pembayaran Terverifikasi!</b>\n\n"
                        . "Halo! Pembayaran Anda telah dikonfirmasi dan disetujui oleh admin.\n\n"
                        . "📦 <b>Detail Instansi Anda:</b>\n"
                        . "• Layanan: <b>{$serviceLabel}</b>\n"
                        . "• URL: <b>{$clientUrl}</b>\n"
                        . "• Masa Aktif: <b>s.d. {$expiresAt}</b>\n\n"
                        . "🔗 Akses URL Anda sekarang:\n"
                        . "<a href=\"{$clientUrl}\">{$clientUrl}</a>\n\n"
                        . "Jika ada masalah, hubungi admin: @awbuilderadmin";

                    Http::post("https://api.telegram.org/bot{$buyerToken}/sendMessage", [
                        'chat_id'    => $buyerChatId,
                        'text'       => $buyerMessage,
                        'parse_mode' => 'HTML',
                    ]);
                }

                return response()->json(['status' => 'approved']);
            } catch (\Throwable $e) {
                Log::channel('deploy-audit')->error('[PaymentBot] Manual approval error', [
                    'deployment_id' => $deploymentId,
                    'error' => $e->getMessage(),
                ]);
                $this->adminBot->answerCallbackQuery($callbackQueryId, 'Error: ' . $e->getMessage(), true);
                return response()->json(['status' => 'error_activation']);
            }
        }

        return response()->json(['status' => 'unknown_callback']);
    }
}
