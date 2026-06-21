<?php

namespace App\Actions;

use App\DataTransferObjects\LeadAnalysisResult;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\ServiceTemplate;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Cache;
use Exception;

class DeployServiceAction
{
    /**
     * Execute the deployment of a service template.
     *
     * @param LeadAnalysisResult $result
     * @return Deployment
     * @throws Exception
     */
    public function execute(LeadAnalysisResult $result): Deployment
    {
        $serviceTemplate = ServiceTemplate::findOrFail($result->serviceTemplateId);

        $templateBasePath = config('deploy.template_base_path');
        $instanceBasePath = config('deploy.instance_base_path');

        $templatePath = $templateBasePath . '/' . $serviceTemplate->template_path;
        $instancePath = $instanceBasePath . '/' . $result->clientSlug;

        // 1. Pre-deployment DB record creation (Status: pending)
        $deployment = Deployment::create([
            'source' => $result->source,
            'lead_reference' => $result->leadReference,
            'service_template_id' => $result->serviceTemplateId,
            'client_slug' => $result->clientSlug,
            'instance_path' => $instancePath,
            'started_at' => now(),
            'expires_at' => $result->expiresAt,
            'status' => DeploymentStatus::PENDING,
            'price' => $result->price,
            'raw_llm_response' => $result->rawLlmResponse,
        ]);

        Log::channel('deploy-audit')->info('Started deployment process.', [
            'deployment_id' => $deployment->id,
            'client_slug' => $result->clientSlug,
            'template_path' => $templatePath,
            'instance_path' => $instancePath
        ]);

        // Start stage: replication
        Cache::put("sandbox_status_{$result->leadReference}", [
            'stage' => 'replication',
            'status' => 'pending',
            'message' => 'Cloning template directory and injecting configuration files...'
        ], 600);

        try {
            // Check if this is a blank/empty template (start from scratch)
            $isBlank = ($serviceTemplate->key === 'blank' || $serviceTemplate->template_path === 'blank' || $serviceTemplate->template_path === 'empty');

            if (!$isBlank) {
                // Validate template path existence
                if (!File::isDirectory($templatePath)) {
                    throw new Exception("Template directory does not exist: {$templatePath}");
                }
            }

            // Ensure base instances path exists
            if (!File::isDirectory($instanceBasePath)) {
                File::makeDirectory($instanceBasePath, 0755, true);
            }

            // 2. Clone template (filesystem copy) or create empty directory
            if (File::exists($instancePath)) {
                Log::channel('deploy-audit')->info('Destination directory already exists. Overwriting/deleting existing directory.', [
                    'instance_path' => $instancePath
                ]);
                File::deleteDirectory($instancePath);
            }

            if ($isBlank) {
                Log::channel('deploy-audit')->info('Creating empty instance directory for blank template...', [
                    'to' => $instancePath
                ]);
                File::makeDirectory($instancePath, 0755, true);
            } else {
                Log::channel('deploy-audit')->info('Copying template directory...', [
                    'from' => $templatePath,
                    'to' => $instancePath
                ]);
                File::copyDirectory($templatePath, $instancePath);
            }

            // 3. Write .env file
            $exampleEnvPath = $instancePath . '/.env.example';
            $envPath = $instancePath . '/.env';
            $envContent = '';

            if (File::exists($exampleEnvPath)) {
                $envContent = File::get($exampleEnvPath);
            }

            $injectedValues = [
                'CLIENT_SLUG' => $result->clientSlug,
                'DEPLOY_EXPIRES_AT' => $result->expiresAt->toIso8601String(),
                'DEPLOY_STARTED_AT' => now()->toIso8601String(),
            ];

            foreach ($injectedValues as $key => $val) {
                if (preg_match("/^{$key}=/m", $envContent)) {
                    $envContent = preg_replace("/^{$key}=.*/m", "{$key}={$val}", $envContent);
                } else {
                    $envContent .= "\n{$key}={$val}";
                }
            }

            File::put($envPath, trim($envContent) . "\n");

            // Redacted log of injected config (no secrets)
            Log::channel('deploy-audit')->info('Injected .env parameters.', [
                'injected_keys' => array_keys($injectedValues)
            ]);

            // 3.5 Inject send.php configuration if exists
            $sendPhpPath = $instancePath . '/config/send.php';
            if (File::exists($sendPhpPath)) {
                $rawResponseData = json_decode($result->rawLlmResponse, true) ?? [];
                
                $telegramToken = $rawResponseData['telegram_token'] ?? $rawResponseData['token'] ?? $rawResponseData['bot_token'] ?? null;
                $telegramChatId = $rawResponseData['telegram_chat_id'] ?? $rawResponseData['chat_id'] ?? $rawResponseData['id_chat'] ?? null;

                if ($telegramToken || $telegramChatId) {
                    $sendPhpContent = File::get($sendPhpPath);
                    
                    if ($telegramToken) {
                        $sendPhpContent = preg_replace('/\$service_token\s*=\s*\'.*?\';/', "\$service_token = '{$telegramToken}';", $sendPhpContent);
                    }
                    if ($telegramChatId) {
                        $sendPhpContent = preg_replace('/\$service_chat\s*=\s*\'.*?\';/', "\$service_chat = '{$telegramChatId}';", $sendPhpContent);
                    }
                    
                    File::put($sendPhpPath, $sendPhpContent);
                    
                    Log::channel('deploy-audit')->info('Injected custom config/send.php parameters.', [
                        'has_token' => !empty($telegramToken),
                        'has_chat_id' => !empty($telegramChatId)
                    ]);
                }
            }

            // 4. Execute deploy.sh (optional)
            $scriptPath = $instancePath . '/deploy.sh';
            if (File::exists($scriptPath)) {
                Log::channel('deploy-audit')->info('Executing deploy.sh script...', [
                    'script' => $scriptPath,
                    'arg' => $result->clientSlug
                ]);

                // Start stage: script_execution
                Cache::put("sandbox_status_{$result->leadReference}", [
                    'stage' => 'script_execution',
                    'status' => 'pending',
                    'message' => 'Running post-cloning deploy.sh configuration script...'
                ], 600);

                // Execute using process with array format and timeout
                $processResult = Process::path($instancePath)
                    ->timeout(60)
                    ->run(['bash', 'deploy.sh', $result->clientSlug]);

                if (!$processResult->successful()) {
                    $stdout = $processResult->output();
                    $stderr = $processResult->errorOutput();

                    Log::channel('deploy-audit')->error('deploy.sh execution failed.', [
                        'deployment_id' => $deployment->id,
                        'exit_code' => $processResult->exitCode(),
                        'stdout' => $stdout,
                        'stderr' => $stderr
                    ]);

                    throw new Exception("deploy.sh script failed with exit code: " . $processResult->exitCode());
                }
            } else {
                Log::channel('deploy-audit')->info('No deploy.sh script found in cloned template. Skipping script execution.');
            }

            // 5. Success finalization
            $finalStatus = ($result->source === 'telegram') ? DeploymentStatus::PENDING_PAYMENT : DeploymentStatus::ACTIVE;
            $deployment->update([
                'status' => $finalStatus,
            ]);

            Log::channel('deploy-audit')->info('Deployment completed successfully.', [
                'deployment_id' => $deployment->id,
                'client_slug' => $result->clientSlug,
                'status' => $finalStatus->value
            ]);

            $cacheMessage = ($finalStatus === DeploymentStatus::PENDING_PAYMENT)
                ? 'Deployment built successfully. Awaiting payment confirmation.'
                : 'Deployment active and running successfully.';

            Cache::put("sandbox_status_{$result->leadReference}", [
                'stage' => 'completed',
                'status' => $finalStatus->value,
                'message' => $cacheMessage
            ], 600);

            return $deployment;

        } catch (Exception $e) {
            // Rollback files
            if (File::isDirectory($instancePath)) {
                Log::channel('deploy-audit')->info('Rolling back deployment: deleting instance directory.', [
                    'instance_path' => $instancePath
                ]);
                File::deleteDirectory($instancePath);
            }

            // Update DB status to failed
            $deployment->update([
                'status' => DeploymentStatus::FAILED,
            ]);

            Log::channel('deploy-audit')->error('Deployment transaction failed and rolled back.', [
                'deployment_id' => $deployment->id,
                'error' => $e->getMessage()
            ]);

            // Cache stage failure
            $failedStage = 'replication';
            if (isset($scriptPath) && File::exists($scriptPath)) {
                $failedStage = 'script_execution';
            }
            Cache::put("sandbox_status_{$result->leadReference}", [
                'stage' => $failedStage,
                'status' => 'failed',
                'message' => 'Deployment failed: ' . $e->getMessage()
            ], 600);

            throw $e;
        }
    }
}
