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
use Throwable;

class DeployDemoInstanceJob implements ShouldQueue
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
        public ServiceTemplate $template
    ) {}

    /**
     * Execute the job.
     */
    public function handle(DeployServiceAction $deployAction): void
    {
        $clientSlug = "demo-" . $this->template->key;

        Log::channel('deploy-audit')->info("Processing automated demo deploy job for key: {$this->template->key}");

        // Check if active or pending deployment already exists with this slug
        $exists = Deployment::where('client_slug', $clientSlug)
            ->whereIn('status', [DeploymentStatus::ACTIVE, DeploymentStatus::PENDING])
            ->exists();

        if ($exists) {
            Log::channel('deploy-audit')->info("Demo deployment already exists for key: {$this->template->key}. Skipping.");
            return;
        }

        try {
            // Build DTO
            $result = new \App\DataTransferObjects\LeadAnalysisResult(
                serviceTemplateId: $this->template->id,
                duration: ServiceDuration::ONE_YEAR, // Use standard duration enum
                clientSlug: $clientSlug,
                expiresAt: now()->addYears(10), // Demo instance is active for 10 years
                source: 'system', // active status
                leadReference: 'demo_' . $this->template->key . '_' . uniqid(),
                price: 0,
                rawLlmResponse: json_encode([
                    'telegram_token' => '',
                    'telegram_chat_id' => '',
                    'is_demo' => true
                ])
            );

            // Execute deployment
            $deployAction->execute($result);
            Log::channel('deploy-audit')->info("Successfully deployed demo instance for template: {$this->template->key}");
        } catch (Throwable $e) {
            Log::channel('deploy-audit')->error("Failed to deploy demo instance for template {$this->template->key}: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
