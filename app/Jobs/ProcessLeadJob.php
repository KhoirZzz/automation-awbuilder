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
        DeployServiceAction $deployAction
    ): void {
        Log::channel('deploy-audit')->info('Processing lead job.', [
            'lead_reference' => $this->leadReference,
            'source' => $this->source,
        ]);

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

            // Re-throw so Laravel's queue manager retries the job
            throw $e;
        }

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
            return;
        }

        try {
            // Execute the deployment action
            $deployAction->execute($analysisResult);
        } catch (Throwable $e) {
            // Execution/script/filesystem error is generally non-transient unless filesystem/disk is full,
            // but the state has been rolled back. We log and complete the job.
            Log::channel('deploy-audit')->error('Deployment execution failed.', [
                'lead_reference' => $this->leadReference,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // Do not re-throw to avoid infinite retries on static script failures
        }
    }
}
