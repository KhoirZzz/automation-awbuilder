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

        $detectedNominal = ($extraction['success'] && $extraction['nominal'] !== null) ? $extraction['nominal'] : null;

        // ── 3. Find matching deployment ────────────────────────────────────────
        $deployment = null;
        $slugHint = $this->extractSlugFromText($this->messageText);

        // Try matching by slug hint first (most reliable)
        if ($slugHint) {
            $deployment = Deployment::where('status', DeploymentStatus::PENDING_PAYMENT->value)
                ->where('client_slug', $slugHint)
                ->latest()
                ->first();
        }

        // If not found by slug hint, try to match by detected nominal
        if (!$deployment && $detectedNominal !== null) {
            // Allow ±5% tolerance for bank fee rounding/uniqueness addition
            $tolerance = (int) round($detectedNominal * 0.05);
            $deployment = Deployment::where('status', DeploymentStatus::PENDING_PAYMENT->value)
                ->whereBetween('expected_price', [
                    $detectedNominal - $tolerance,
                    $detectedNominal + $tolerance,
                ])
                ->latest()
                ->first();
        }

        // ── 4. Forward photo to admin with details and Approve button ──────────
        $adminChatId = config('services.telegram.admin_chat_id');
        if ($adminChatId) {
            $keyboard = [];
            if ($deployment) {
                $keyboard[] = [
                    [
                        'text' => 'Approve Pembayaran ✅',
                        'callback_data' => 'approve_dep:' . $deployment->id
                    ]
                ];
            }

            $caption = "📩 <b>Bukti Bayar Baru Masuk!</b>\n\n"
                . "• Sender Chat ID: <code>{$this->senderChatId}</code>\n"
                . "• Caption User: <i>" . ($this->messageText ?: 'tidak ada') . "</i>\n"
                . "• Nominal Terdeteksi (AI): <b>" . ($detectedNominal ? "Rp " . number_format($detectedNominal, 0, ',', '.') : 'Gagal dibaca') . "</b>\n";

            if ($deployment) {
                $caption .= "• Subdomain Cocok: <b>{$deployment->client_slug}</b>\n"
                    . "• Tagihan Expected: <b>Rp " . number_format($deployment->expected_price, 0, ',', '.') . "</b>\n\n"
                    . "Silakan periksa mutasi rekening Anda. Jika dana sudah masuk, klik tombol di bawah untuk menyetujui (approve) dan mengaktifkan instansi secara otomatis.";
            } else {
                $caption .= "• Subdomain Cocok: <b>Tidak ditemukan</b>\n\n"
                    . "⚠️ <b>Error:</b> Tidak dapat mencocokkan bukti bayar dengan data pending order. "
                    . "Silakan minta pembeli menyertakan subdomain yang tepat di caption, atau lakukan aktivasi manual via Dashboard Admin.";
            }

            $adminBot->sendPhotoWithKeyboard($adminChatId, $this->fileId, $caption, $keyboard);
        }
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
