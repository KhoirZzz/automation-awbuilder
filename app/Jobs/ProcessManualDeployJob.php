<?php

namespace App\Jobs;

use App\Actions\DeployServiceAction;
use App\Enums\DeploymentStatus;
use App\Enums\ServiceDuration;
use App\Models\ServiceTemplate;
use App\Models\Deployment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ProcessManualDeployJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
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
        public array $params,
        public string $leadReference
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        DeployServiceAction $deployAction
    ): void {
        Log::channel('deploy-audit')->info('Processing manual deploy job.', [
            'lead_reference' => $this->leadReference,
            'params' => $this->params
        ]);

        // Start stage: llm_analysis -> success (Skipped for manual)
        Cache::put("sandbox_status_{$this->leadReference}", [
            'stage' => 'llm_analysis',
            'status' => 'success',
            'message' => 'Manual Deploy input received. LLM Logic Extraction bypassed.'
        ], 600);

        // Start stage: validation
        Cache::put("sandbox_status_{$this->leadReference}", [
            'stage' => 'validation',
            'status' => 'pending',
            'message' => 'Validating manual input fields against system policies...'
        ], 600);

        try {
            $serviceTemplate = ServiceTemplate::where('key', $this->params['service_key'])->first();
            if (!$serviceTemplate) {
                throw new \Exception("Template dengan key '{$this->params['service_key']}' tidak ditemukan.");
            }
            if (!$serviceTemplate->is_active) {
                throw new \Exception("Template '{$serviceTemplate->name}' sedang tidak aktif.");
            }

            // Validate slug compliance
            $clientSlug = strtolower(trim($this->params['client_slug_request']));
            if (!preg_match('/^[a-z0-9](-?[a-z0-9])*$/', $clientSlug) || strlen($clientSlug) > 63 || strlen($clientSlug) < 2) {
                throw new \Exception('Slug/Subdomain tidak sesuai format DNS (hanya boleh huruf kecil, angka, dan strip).');
            }

            // Check reserved words
            $reserved = config('deploy.reserved_slugs', []);
            if (in_array($clientSlug, $reserved)) {
                throw new \Exception('Slug merupakan kata terlarang sistem.');
            }

            // Check duplicates
            $duplicate = Deployment::where('client_slug', $clientSlug)
                ->whereIn('status', [DeploymentStatus::PENDING, DeploymentStatus::ACTIVE])
                ->first();
            if ($duplicate) {
                throw new \Exception('Subdomain/Slug ini sudah aktif digunakan.');
            }

            // Map duration enum
            $durationEnum = ServiceDuration::tryFrom($this->params['durasi']);
            if (!$durationEnum) {
                throw new \Exception('Durasi tidak valid.');
            }
        } catch (\Exception $e) {
            Log::channel('deploy-audit')->warning('Manual deploy validation failed.', [
                'lead_reference' => $this->leadReference,
                'error' => $e->getMessage()
            ]);

            Cache::put("sandbox_status_{$this->leadReference}", [
                'stage' => 'validation',
                'status' => 'failed',
                'message' => 'Validation failure: ' . $e->getMessage()
            ], 600);
            return;
        }

        // Set stage validation to success
        Cache::put("sandbox_status_{$this->leadReference}", [
            'stage' => 'validation',
            'status' => 'success',
            'message' => 'Input validated successfully. Ready for provisioning.'
        ], 600);

        try {
            $price = !empty($this->params['price']) ? (int)$this->params['price'] : null;

            // Build DTO
            $result = new \App\DataTransferObjects\LeadAnalysisResult(
                serviceTemplateId: $serviceTemplate->id,
                duration: $durationEnum,
                clientSlug: $clientSlug,
                expiresAt: $durationEnum->calculateExpiry(),
                source: 'agent', // Sets deployment status to ACTIVE directly upon successful deploy.sh run
                leadReference: $this->leadReference,
                price: $price,
                rawLlmResponse: json_encode([
                    'telegram_token' => $this->params['telegram_token'] ?? '',
                    'telegram_chat_id' => $this->params['telegram_chat_id'] ?? '',
                    'target_url' => $this->params['target_url'] ?? null,
                    'output_pdf' => $this->params['output_pdf'] ?? null,
                ])
            );

            // Execute deployment
            $deployAction->execute($result);

        } catch (Throwable $e) {
            Log::channel('deploy-audit')->error('Manual deployment execution failed.', [
                'lead_reference' => $this->leadReference,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // Cache update is handled inside DeployServiceAction on failure
        }
    }
}
