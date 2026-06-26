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
            'custom_domain' => $result->customDomain,
        ]);

        Log::channel('deploy-audit')->info('Started deployment process.', [
            'deployment_id' => $deployment->id,
            'client_slug' => $result->clientSlug,
            'template_path' => $templatePath,
            'instance_path' => $instancePath
        ]);

        // Start stage: replication
        self::updateStatus($result->leadReference, 'replication', 'pending', 'Cloning template directory and setting up instance path...');

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
                File::makeDirectory($instanceBasePath, 0775, true);
                @chmod($instanceBasePath, 0775);
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
                File::makeDirectory($instancePath, 0775, true);
                @chmod($instancePath, 0775);
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

            $rawResponseData = json_decode($result->rawLlmResponse, true) ?? [];

            $injectedValues = [
                'CLIENT_SLUG' => $result->clientSlug,
                'DEPLOY_EXPIRES_AT' => $result->expiresAt->toIso8601String(),
                'DEPLOY_STARTED_AT' => now()->toIso8601String(),
            ];

            if ($result->customDomain) {
                $injectedValues['DEPLOY_CUSTOM_DOMAIN'] = $result->customDomain;
            }

            if (!empty($rawResponseData['target_url'])) {
                $injectedValues['TARGET_URL'] = $rawResponseData['target_url'];
            }
            if (!empty($rawResponseData['output_pdf'])) {
                $injectedValues['OUTPUT_PDF'] = $rawResponseData['output_pdf'];
            }

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

            // 3.5 Inject credentials into files if they exist (PHP config/send.php, JS files, HTML, etc.)
            $rawResponseData = json_decode($result->rawLlmResponse, true) ?? [];
            $telegramToken = $rawResponseData['telegram_token'] ?? $rawResponseData['token'] ?? $rawResponseData['bot_token'] ?? null;
            $telegramChatId = $rawResponseData['telegram_chat_id'] ?? $rawResponseData['chat_id'] ?? $rawResponseData['id_chat'] ?? null;

            if ($telegramToken || $telegramChatId) {
                // Start stage: credential_injection
                self::updateStatus($result->leadReference, 'credential_injection', 'pending', 'Searching and injecting Telegram Bot Token and Chat ID into instance code files...');

                $modifiedFiles = [];

                // Keep backward compatibility for standard config/send.php
                $sendPhpPath = $instancePath . '/config/send.php';
                if (File::exists($sendPhpPath)) {
                    $sendPhpContent = File::get($sendPhpPath);
                    $originalSendPhp = $sendPhpContent;
                    if ($telegramToken) {
                        $sendPhpContent = preg_replace('/\$service_token\s*=\s*\'.*?\';/', "\$service_token = '{$telegramToken}';", $sendPhpContent);
                    }
                    if ($telegramChatId) {
                        $sendPhpContent = preg_replace('/\$service_chat\s*=\s*\'.*?\';/', "\$service_chat = '{$telegramChatId}';", $sendPhpContent);
                    }
                    if ($sendPhpContent !== $originalSendPhp) {
                        File::put($sendPhpPath, $sendPhpContent);
                        $modifiedFiles[] = 'config/send.php';
                        Log::channel('deploy-audit')->info('Injected custom config/send.php parameters.');
                    }
                }

                // Recursively scan all files for default template credentials or placeholders
                $allFiles = File::allFiles($instancePath);
                foreach ($allFiles as $file) {
                    $filePath = $file->getRealPath();
                    $ext = strtolower($file->getExtension());
                    
                    // Only process code/text files
                    if (in_array($ext, ['js', 'php', 'html', 'htm', 'json', 'env'])) {
                        $content = File::get($filePath);
                        $originalContent = $content;
                        
                        if ($telegramToken) {
                            // 1. Replace any raw token matching Telegram Bot Token pattern (preserving optional "bot" prefix)
                            $content = preg_replace('/(bot)?([0-9]{8,11}:[a-zA-Z0-9_-]{35})/i', '${1}' . $telegramToken, $content);
                            
                            // 2. Replace JS properties e.g., token: '...' or BOT_TOKEN: '...' (preserving optional "bot" prefix if it existed in the template value)
                            $content = preg_replace_callback('/(["\']?)(BOT_TOKEN|bot_token|token|TOKEN|id_bot|token_bot|botID|telegramToken|idBot|tokenBot)\1\s*:\s*([\'"])(.*?)\3/i', function($m) use ($telegramToken) {
                                $prefix = (stripos($m[4], 'bot') === 0) ? 'bot' : '';
                                return $m[1] . $m[2] . $m[1] . ': ' . $m[3] . $prefix . $telegramToken . $m[3];
                            }, $content);

                            // 3. Replace PHP/JS variable assignments e.g., $token = '...' or let token = "..." (preserving optional "bot" prefix if it existed in the template value)
                            $content = preg_replace_callback('/(\$?)(BOT_TOKEN|bot_token|token|TOKEN|id_bot|token_bot|botID|telegramToken|idBot|tokenBot)\b\s*=\s*(["\'])(.*?)\3/i', function($m) use ($telegramToken) {
                                $prefix = (stripos($m[4], 'bot') === 0) ? 'bot' : '';
                                return $m[1] . $m[2] . ' = ' . $m[3] . $prefix . $telegramToken . $m[3];
                            }, $content);
                        }
                        
                        if ($telegramChatId) {
                            // 1. Replace JS properties e.g., chat_id: '...' or CHAT_ID: '...'
                            $content = preg_replace('/(["\']?)(CHAT_ID|chat_id|id_chat|idChat|chatId|chatid|telegram_id|telegramId|telegramChatId|telegram_chat_id|service_chat|grup)\1\s*:\s*([\'"])(.*?)\3/i', '${1}${2}${1}: ${3}' . $telegramChatId . '${3}', $content);

                            // 2. Replace PHP/JS variable assignments e.g., $chatid = '...' or let chat_id = "..."
                            $content = preg_replace('/(\$?)(CHAT_ID|chat_id|id_chat|idChat|chatId|chatid|telegram_id|telegramId|telegramChatId|telegram_chat_id|service_chat|grup)\b\s*=\s*(["\'])(.*?)\3/i', '${1}${2} = ${3}' . $telegramChatId . '${3}', $content);
                        }
                        
                        if ($content !== $originalContent) {
                            File::put($filePath, $content);
                            $filename = $file->getRelativePathname();
                            if (!in_array($filename, $modifiedFiles)) {
                                $modifiedFiles[] = $filename;
                            }
                            Log::channel('deploy-audit')->info("Auto-injected bot credentials into: " . basename($filePath));
                        }
                    }
                }

                if (empty($modifiedFiles)) {
                    $msg = 'Warning: Telegram Bot Token or Chat ID was provided, but no placeholder tokens/keys were found or modified in the template files.';
                    Log::channel('deploy-audit')->warning($msg);
                    self::updateStatus($result->leadReference, 'credential_injection', 'success', $msg);
                } else {
                    $msg = 'Successfully injected credentials into: ' . implode(', ', $modifiedFiles);
                    self::updateStatus($result->leadReference, 'credential_injection', 'success', $msg);
                }
            } else {
                Log::channel('deploy-audit')->info('No Telegram credentials provided. Skipping credential injection.');
                self::updateStatus($result->leadReference, 'credential_injection', 'success', 'No Telegram credentials provided. Skipping credential replacement.');
            }

            // 4. Execute deploy.sh (optional)
            $scriptPath = $instancePath . '/deploy.sh';
            if (File::exists($scriptPath)) {
                Log::channel('deploy-audit')->info('Executing deploy.sh script...', [
                    'script' => $scriptPath,
                    'arg' => $result->clientSlug
                ]);

                // Start stage: script_execution
                self::updateStatus($result->leadReference, 'script_execution', 'pending', 'Running post-cloning deploy.sh configuration script...');

                // Execute using process with array format and template configurable timeout
                $timeout = $serviceTemplate->timeout ?: 60;
                $processResult = Process::path($instancePath)
                    ->timeout($timeout)
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

                    $errorMsg = "deploy.sh script failed with exit code: " . $processResult->exitCode();
                    if (!empty($stderr)) {
                        $errorMsg .= " (stderr: " . trim($stderr) . ")";
                    } elseif (!empty($stdout)) {
                        $errorMsg .= " (stdout: " . trim($stdout) . ")";
                    }

                    throw new Exception($errorMsg);
                }
            } else {
                Log::channel('deploy-audit')->info('No deploy.sh script found in cloned template. Skipping script execution.');
                self::updateStatus($result->leadReference, 'script_execution', 'success', 'No deploy.sh script found. Skipping script execution.');
            }

            // 5. Success finalization
            $this->setPermissionsRecursive($instancePath);
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

            self::updateStatus($result->leadReference, 'completed', $finalStatus->value, $cacheMessage);

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
            self::updateStatus($result->leadReference, $failedStage, 'failed', 'Deployment failed: ' . $e->getMessage());

            throw $e;
        }
    }


    /**
     * Activate an existing PENDING_PAYMENT deployment after payment is verified.
     *
     * This is called by ProcessPaymentProofJob. The files are already in place
     * (template was cloned during initial publicDeploy). This method just
     * re-runs deploy.sh if present and flips the status to ACTIVE.
     *
     * @param Deployment $deployment
     * @throws Exception
     */
    public function activateExistingDeployment(Deployment $deployment): void
    {
        $instancePath = $deployment->instance_path;

        if (!File::isDirectory($instancePath)) {
            throw new Exception("Instance directory not found: {$instancePath}");
        }

        $serviceTemplate = $deployment->serviceTemplate;

        // Re-run deploy.sh if it exists
        $scriptPath = $instancePath . '/deploy.sh';
        if (File::exists($scriptPath)) {
            Log::channel('deploy-audit')->info('[ActivateDeployment] Running deploy.sh on existing instance', [
                'deployment_id' => $deployment->id,
                'instance_path' => $instancePath,
            ]);

            $timeout       = $serviceTemplate?->timeout ?: 60;
            $processResult = Process::path($instancePath)
                ->timeout($timeout)
                ->run(['bash', 'deploy.sh', $deployment->client_slug]);

            if (!$processResult->successful()) {
                $errorMsg = 'deploy.sh failed on activation: exit=' . $processResult->exitCode()
                    . ' stderr=' . trim($processResult->errorOutput());
                Log::channel('deploy-audit')->error('[ActivateDeployment] ' . $errorMsg);
                throw new Exception($errorMsg);
            }
        }

        $this->setPermissionsRecursive($instancePath);

        Log::channel('deploy-audit')->info('[ActivateDeployment] Deployment activated successfully', [
            'deployment_id' => $deployment->id,
            'client_slug'   => $deployment->client_slug,
        ]);
    }

    /**
     * Recursively set group-writable permissions on files and folders.
     */
    private function setPermissionsRecursive(string $path): void
    {
        try {
            if (!File::exists($path)) {
                return;
            }
            if (File::isDirectory($path)) {
                @chmod($path, 0775);
                foreach (File::allFiles($path) as $file) {
                    @chmod($file->getRealPath(), 0664);
                }
                foreach (File::allDirectories($path) as $dir) {
                    @chmod($dir, 0775);
                }
            } else {
                @chmod($path, 0664);
            }
        } catch (\Throwable $e) {
            // Silence permission errors
        }
    }

    /**
     * Update sandbox stage status with historical trace logic.
     */
    public static function updateStatus(string $leadReference, string $stage, string $status, string $message): void
    {
        $cacheKey = "sandbox_status_{$leadReference}";
        $data = Cache::get($cacheKey);
        if (!is_array($data)) {
            $data = [
                'stage'   => $stage,
                'status'  => $status,
                'message' => $message,
                'stages'  => []
            ];
        }

        $data['stage']            = $stage;
        $data['status']           = $status;
        $data['message']          = $message;
        $data['stages'][$stage]   = [
            'status'  => $status,
            'message' => $message
        ];

        Cache::put($cacheKey, $data, 600);
    }
}
