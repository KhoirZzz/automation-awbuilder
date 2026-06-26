<?php

namespace App\Jobs;

use App\Models\Deployment;
use App\Enums\DeploymentStatus;
use App\Services\PaymentVisionService;
use App\Services\TelegramBotService;
use App\Actions\DeployServiceAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

/**
 * ProcessPaymentProofJob
 *
 * Dispatched when a buyer sends a payment proof image to the admin Telegram bot.
 *
 * Flow:
 * 1. Download the image from Telegram (using file_id)
 * 2. Send image to PaymentVisionService (LLM) to extract nominal
 * 3. Find matching pending_payment deployment by comparing nominal ± tolerance
 * 4. If match found → trigger DeployServiceAction to activate deployment
 * 5. Notify buyer via their own bot token
 * 6. Notify admin of the result
 */
class ProcessPaymentProofJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    /**
     * @param string      $fileId        Telegram file_id of the payment proof photo
     * @param string      $senderChatId  The chat_id of the person who sent the proof to admin bot
     * @param string      $messageText   Any accompanying text message (may contain slug hint)
     * @param int|null    $messageId     Telegram message ID (for admin reply context)
     */
    public function __construct(
        private readonly string  $fileId,
        private readonly string  $senderChatId,
        private readonly string  $messageText = '',
        private readonly ?int    $messageId = null,
    ) {}

    public function handle(
        PaymentVisionService $visionService,
        TelegramBotService   $adminBot,
        DeployServiceAction  $deployAction,
    ): void {
        Log::channel('deploy-audit')->info('[PaymentProof] Job started', [
            'file_id'       => $this->fileId,
            'sender_chat_id'=> $this->senderChatId,
            'message_text'  => $this->messageText,
        ]);

        // ── 1. Download image from Telegram ───────────────────────────────────
        $imageData = $adminBot->downloadFile($this->fileId);

        if (!$imageData) {
            Log::channel('deploy-audit')->error('[PaymentProof] Failed to download image from Telegram');
            $adminBot->sendMessage(
                config('services.telegram.admin_chat_id'),
                "⚠️ <b>Gagal mengunduh gambar bukti bayar.</b>\n\n• File ID: <code>{$this->fileId}</code>\n• Sender: <code>{$this->senderChatId}</code>",
            );
            return;
        }

        // ── 2. Extract nominal via LLM ─────────────────────────────────────────
        $base64Image = base64_encode($imageData['content']);
        $mimeType    = $imageData['mime'] ?? 'image/jpeg';

        $extraction = $visionService->extractNominalFromImage($base64Image, $mimeType);

        Log::channel('deploy-audit')->info('[PaymentProof] LLM extraction result', $extraction);

        if (!$extraction['success'] || $extraction['nominal'] === null) {
            $adminBot->sendMessage(
                config('services.telegram.admin_chat_id'),
                "🔍 <b>LLM tidak dapat membaca nominal dari bukti bayar.</b>\n\n"
                . "• Sender: <code>{$this->senderChatId}</code>\n"
                . "• Confidence: <code>{$extraction['confidence']}</code>\n"
                . "• Pesan: <i>{$this->messageText}</i>\n\n"
                . "Silakan approve manual via dashboard.",
            );
            return;
        }

        $detectedNominal = $extraction['nominal'];

        // ── 3. Find matching deployment ────────────────────────────────────────
        // Allow ±5% tolerance for bank fee rounding
        $tolerance = (int) round($detectedNominal * 0.05);

        // Try to narrow by slug hint from message text
        $slugHint = $this->extractSlugFromText($this->messageText);

        $query = Deployment::where('status', DeploymentStatus::PENDING_PAYMENT->value)
            ->whereBetween('expected_price', [
                $detectedNominal - $tolerance,
                $detectedNominal + $tolerance,
            ]);

        if ($slugHint) {
            $query->where('client_slug', $slugHint);
        }

        $deployment = $query->latest()->first();

        if (!$deployment) {
            // Try without slug hint if hint was applied
            if ($slugHint) {
                $deployment = Deployment::where('status', DeploymentStatus::PENDING_PAYMENT->value)
                    ->whereBetween('expected_price', [
                        $detectedNominal - $tolerance,
                        $detectedNominal + $tolerance,
                    ])
                    ->latest()
                    ->first();
            }

            if (!$deployment) {
                Log::channel('deploy-audit')->warning('[PaymentProof] No matching deployment found', [
                    'detected_nominal' => $detectedNominal,
                    'slug_hint'        => $slugHint,
                ]);

                $adminBot->sendMessage(
                    config('services.telegram.admin_chat_id'),
                    "⚠️ <b>Tidak ada deployment yang cocok dengan nominal ini.</b>\n\n"
                    . "• Nominal Terdeteksi: <b>Rp " . number_format($detectedNominal, 0, ',', '.') . "</b>\n"
                    . "• Slug Hint: <code>" . ($slugHint ?? 'tidak ada') . "</code>\n"
                    . "• Sender: <code>{$this->senderChatId}</code>\n\n"
                    . "Minta pembeli menyertakan subdomain-nya atau approve manual.",
                );
                return;
            }
        }

        // ── 4. Activate deployment ────────────────────────────────────────────
        try {
            $deployment->update([
                'status'              => DeploymentStatus::ACTIVE->value,
                'started_at'          => now(),
                'payment_verified_at' => now(),
            ]);

            // Run the actual server deployment script
            $deployAction->activateExistingDeployment($deployment);

            Log::channel('deploy-audit')->info('[PaymentProof] Deployment activated', [
                'deployment_id' => $deployment->id,
                'client_slug'   => $deployment->client_slug,
            ]);
        } catch (\Throwable $e) {
            Log::channel('deploy-audit')->error('[PaymentProof] Failed to activate deployment', [
                'deployment_id' => $deployment->id,
                'error'         => $e->getMessage(),
            ]);

            $adminBot->sendMessage(
                config('services.telegram.admin_chat_id'),
                "❌ <b>Pembayaran terverifikasi tapi deployment gagal diaktifkan!</b>\n\n"
                . "• Slug: <b>{$deployment->client_slug}</b>\n"
                . "• Error: <code>" . e($e->getMessage()) . "</code>\n\n"
                . "Coba retry manual di dashboard.",
            );
            return;
        }

        // ── 5. Build client URL ────────────────────────────────────────────────
        $clientUrl = "https://{$deployment->client_slug}.mockbuild.shop";

        // ── 6. Notify buyer via their bot token ────────────────────────────────
        $buyerChatId = $deployment->buyer_telegram_chat_id;
        $buyerToken  = $deployment->buyer_telegram_token;

        if ($buyerChatId && $buyerToken) {
            try {
                $serviceTemplate = $deployment->serviceTemplate;
                $serviceLabel    = $serviceTemplate?->name ?? 'Layanan';
                $expiresAt       = $deployment->expires_at?->locale('id')->isoFormat('D MMMM Y') ?? '-';

                $buyerMessage = "✅ <b>Pembayaran Terverifikasi!</b>\n\n"
                    . "Halo! Pembayaran Anda telah dikonfirmasi oleh sistem kami.\n\n"
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

                Log::channel('deploy-audit')->info('[PaymentProof] Buyer notified via their bot', [
                    'buyer_chat_id' => $buyerChatId,
                    'url'           => $clientUrl,
                ]);
            } catch (\Exception $e) {
                Log::channel('deploy-audit')->warning('[PaymentProof] Failed to notify buyer via their bot', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // ── 7. Notify admin of success ─────────────────────────────────────────
        $adminBot->sendMessage(
            config('services.telegram.admin_chat_id'),
            "✅ <b>Pembayaran Terverifikasi & Deployment Aktif!</b>\n\n"
            . "• Slug: <b>{$deployment->client_slug}</b>\n"
            . "• URL: <a href=\"{$clientUrl}\">{$clientUrl}</a>\n"
            . "• Nominal: <b>Rp " . number_format($detectedNominal, 0, ',', '.') . "</b>\n"
            . "• Expected: <b>Rp " . number_format($deployment->expected_price, 0, ',', '.') . "</b>\n"
            . "• Buyer Chat ID: <code>{$buyerChatId}</code>\n"
            . "• Status Notifikasi Buyer: " . ($buyerChatId ? "✅ Terkirim" : "⚠️ Tidak ada chat ID"),
        );
    }

    /**
     * Try to extract a slug from the message text (buyer may write their subdomain).
     */
    private function extractSlugFromText(string $text): ?string
    {
        if (empty($text)) {
            return null;
        }

        // Match subdomain.mockbuild.shop
        if (preg_match('/([a-z0-9][a-z0-9\-]{0,62})\.mockbuild\.shop/i', $text, $m)) {
            return strtolower($m[1]);
        }

        // Match standalone slug-like word
        if (preg_match('/\b([a-z0-9][a-z0-9\-]{1,62})\b/i', $text, $m)) {
            $candidate = strtolower($m[1]);
            // Filter out common words
            $ignore = ['bayar', 'transfer', 'bukti', 'pembayaran', 'saya', 'sudah', 'tolong', 'aktif', 'nih', 'ini'];
            if (!in_array($candidate, $ignore)) {
                return $candidate;
            }
        }

        return null;
    }
}
