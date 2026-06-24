<?php

namespace App\Jobs;

use App\Actions\DeployServiceAction;
use App\Exceptions\HermesResponseException;
use App\Exceptions\InvalidLeadAnalysisException;
use App\Models\ServiceTemplate;
use App\Services\HermesService;
use App\Support\LeadAnalysisValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ProcessLeadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     * We allow retries for transient errors (like HTTP/connection errors).
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $messageText,
        public string $source,
        public string $leadReference
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        HermesService $hermesService,
        LeadAnalysisValidator $validator,
        DeployServiceAction $deployAction,
        \App\Services\TelegramBotService $botService
    ): void {
        Log::channel('deploy-audit')->info('Processing lead job.', [
            'lead_reference' => $this->leadReference,
            'source' => $this->source,
        ]);

        // Extract Telegram chat ID from lead reference if applicable
        $chatId = null;
        if (str_starts_with($this->leadReference, 'tg_')) {
            $parts = explode('_', $this->leadReference);
            $chatId = $parts[1] ?? null;
        }

        // Start stage: llm_analysis
        Cache::put("sandbox_status_{$this->leadReference}", [
            'stage' => 'llm_analysis',
            'status' => 'pending',
            'message' => 'Hermes is analyzing the lead chat text...'
        ], 600);

        // Get all active templates
        $activeTemplates = ServiceTemplate::where('is_active', true)->get();

        try {
            // Call Hermes to analyze the lead text
            $rawAnalysis = $hermesService->analyzeLead($this->messageText, $activeTemplates);
        } catch (HermesResponseException $e) {
            // Transient error (failed to connect, non-200 code, json decode failure)
            Log::channel('deploy-audit')->error('Transient Hermes error during analysis. Job will retry.', [
                'lead_reference' => $this->leadReference,
                'error' => $e->getMessage()
            ]);

            Cache::put("sandbox_status_{$this->leadReference}", [
                'stage' => 'llm_analysis',
                'status' => 'failed',
                'message' => 'Transient Hermes connection failed: ' . $e->getMessage()
            ], 600);

            // Re-throw so Laravel's queue manager retries the job
            throw $e;
        }

        // Start stage: validation
        Cache::put("sandbox_status_{$this->leadReference}", [
            'stage' => 'validation',
            'status' => 'pending',
            'message' => 'Validating extracted metadata against system policies...'
        ], 600);

        try {
            // Validate the raw result from Hermes
            $analysisResult = $validator->validate($rawAnalysis, $this->source, $this->leadReference);
        } catch (InvalidLeadAnalysisException $e) {
            // Non-transient validation failure (not matching whitelist, reserved word, invalid slug format)
            // Logged as warning in validator, job finishes successfully here to prevent retries.
            Log::channel('deploy-audit')->warning('Lead rejected due to validation failure. No retry.', [
                'lead_reference' => $this->leadReference,
                'message' => $e->getMessage()
            ]);

            Cache::put("sandbox_status_{$this->leadReference}", [
                'stage' => 'validation',
                'status' => 'failed',
                'message' => 'Lead rejected due to validation failure: ' . $e->getMessage()
            ], 600);

            if ($chatId) {
                $botService->sendMessage($chatId, "❌ <b>Pemesanan Gagal</b>\n\nMaaf, pesanan Anda tidak dapat diproses karena kesalahan berikut:\n<i>" . $e->getMessage() . "</i>");
            }

            return;
        }

        try {
            // Execute the deployment action
            $deployAction->execute($analysisResult);

            // Send QRIS invoice and notify admin if ordered via Telegram bot
            if ($this->source === 'telegram' && $chatId) {
                $qrisUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=qris_payment_mock_" . $analysisResult->clientSlug;
                
                // Auto-detect static QRIS image in public directory
                if (file_exists(public_path('logo/qris.png'))) {
                    $qrisUrl = asset('logo/qris.png');
                } elseif (file_exists(public_path('logo/qris.jpg'))) {
                    $qrisUrl = asset('logo/qris.jpg');
                } elseif (file_exists(public_path('logo/qris.jpeg'))) {
                    $qrisUrl = asset('logo/qris.jpeg');
                } elseif (file_exists(public_path('qris.png'))) {
                    $qrisUrl = asset('qris.png');
                } elseif (file_exists(public_path('qris.jpg'))) {
                    $qrisUrl = asset('qris.jpg');
                } elseif (file_exists(public_path('qris.jpeg'))) {
                    $qrisUrl = asset('qris.jpeg');
                }

                $formattedPrice = $analysisResult->price ? 'Rp ' . number_format($analysisResult->price, 0, ',', '.') : 'Rp 100.000';
                
                $caption = "<b>📄 INVOICE PEMESANAN DEPLOYMENT</b>\n\nAplikasi Anda berhasil dikonfigurasi di VPS dan siap diaktifkan!\n\n• <b>Subdomain / Slug:</b> {$analysisResult->clientSlug}\n• <b>Durasi Sewa:</b> {$analysisResult->duration->value}\n• <b>Total Pembayaran:</b> {$formattedPrice}\n\nSilakan scan QRIS di atas untuk melakukan pembayaran.\nKirimkan bukti transfer pembayaran Anda ke Admin: <b>@awbuilderadmin</b>.\n\n<i>Aplikasi Anda akan segera diaktifkan dan link URL akan dikirim ke chat ini setelah pembayaran diverifikasi oleh Admin.</i>";
                
                $botService->sendPhoto($chatId, $qrisUrl, $caption);

                // Notify admin
                $adminChatId = config('services.telegram.admin_chat_id');
                if ($adminChatId) {
                    $botService->sendMessage($adminChatId, "<b>🔔 PEMESANAN BARU MENUNGGU PERSETUJUAN</b>\n\n• Client Chat ID: <code>{$chatId}</code>\n• Subdomain: <b>{$analysisResult->clientSlug}</b>\n• Durasi Sewa: <b>{$analysisResult->duration->value}</b>\n• Harga: <b>{$formattedPrice}</b>\n\nSilakan verifikasi bukti pembayaran dari client di @awbuilderadmin lalu ketik:\n<code>/approve {$analysisResult->clientSlug}</code>");
                }
            }
        } catch (Throwable $e) {
            // Execution/script/filesystem error is generally non-transient unless filesystem/disk is full,
            // but the state has been rolled back. We log and complete the job.
            Log::channel('deploy-audit')->error('Deployment execution failed.', [
                'lead_reference' => $this->leadReference,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $errorMessage = $e->getMessage();

            if ($chatId) {
                $botService->sendMessage($chatId, "❌ <b>Pemesanan Gagal</b>\n\nTerjadi kesalahan saat melakukan deployment di VPS:\n<code>" . e($errorMessage) . "</code>\n\nSilakan hubungi admin kami untuk bantuan.");
            }

            // Notify admin
            $adminChatId = config('services.telegram.admin_chat_id');
            if ($adminChatId) {
                $slug = isset($analysisResult) ? $analysisResult->clientSlug : 'N/A';
                $botService->sendMessage($adminChatId, "⚠️ <b>DEPLOYMENT GAGAL</b>\n\n• Subdomain: <b>{$slug}</b>\n• Source: <b>{$this->source}</b>\n• Lead Reference: <code>{$this->leadReference}</code>\n• Error: <code>" . e($errorMessage) . "</code>");
            }
            // Do not re-throw to avoid infinite retries on static script failures
        }
    }
}
