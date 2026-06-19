<?php

namespace App\Console\Commands;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

class AuditExpiredDeployments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy:audit-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Audit active deployments and teardown expired ones';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting audit of expired deployments...');
        Log::channel('deploy-audit')->info('Scheduled audit of expired deployments started.');

        $expiredDeployments = Deployment::where('status', DeploymentStatus::ACTIVE)
            ->where('expires_at', '<', now())
            ->get();

        $this->info("Found {$expiredDeployments->count()} expired deployments.");

        foreach ($expiredDeployments as $deployment) {
            $this->info("Tearing down deployment ID {$deployment->id} (Slug: {$deployment->client_slug})...");
            Log::channel('deploy-audit')->info("Tearing down expired deployment.", [
                'deployment_id' => $deployment->id,
                'client_slug' => $deployment->client_slug,
                'instance_path' => $deployment->instance_path,
            ]);

            try {
                $instancePath = $deployment->instance_path;

                if (File::isDirectory($instancePath)) {
                    $teardownScript = $instancePath . '/teardown.sh';

                    if (File::exists($teardownScript)) {
                        Log::channel('deploy-audit')->info('Executing teardown.sh script...', [
                            'script' => $teardownScript,
                            'arg' => $deployment->client_slug
                        ]);

                        // Run teardown script
                        $processResult = Process::path($instancePath)
                            ->timeout(60)
                            ->run(['bash', 'teardown.sh', $deployment->client_slug]);

                        if (!$processResult->successful()) {
                            Log::channel('deploy-audit')->error('teardown.sh script execution failed.', [
                                'deployment_id' => $deployment->id,
                                'exit_code' => $processResult->exitCode(),
                                'stdout' => $processResult->output(),
                                'stderr' => $processResult->errorOutput()
                            ]);
                            // We still move to archive even if teardown fails, or should we?
                            // Yes, to prevent infinite loops of failing teardowns.
                        } else {
                            Log::channel('deploy-audit')->info('teardown.sh completed successfully.', [
                                'deployment_id' => $deployment->id
                            ]);
                        }
                    } else {
                        Log::channel('deploy-audit')->warning('teardown.sh not found. Skipping execution.', [
                            'deployment_id' => $deployment->id
                        ]);
                    }

                    // Move folder to archive
                    $archiveBase = config('deploy.archive_path');
                    if (!File::isDirectory($archiveBase)) {
                        File::makeDirectory($archiveBase, 0755, true);
                    }

                    $archivePath = $archiveBase . '/' . $deployment->client_slug . '_' . now()->format('Ymd_His');
                    Log::channel('deploy-audit')->info('Archiving deployment folder.', [
                        'from' => $instancePath,
                        'to' => $archivePath
                    ]);

                    File::moveDirectory($instancePath, $archivePath);
                } else {
                    Log::channel('deploy-audit')->warning('Deployment instance directory does not exist on filesystem.', [
                        'deployment_id' => $deployment->id,
                        'instance_path' => $instancePath
                    ]);
                }

                // Update deployment status in DB
                $deployment->update([
                    'status' => DeploymentStatus::EXPIRED,
                ]);

                Log::channel('deploy-audit')->info('Deployment marked as expired.', [
                    'deployment_id' => $deployment->id
                ]);

            } catch (Throwable $e) {
                Log::channel('deploy-audit')->error('Failed during teardown process of deployment.', [
                    'deployment_id' => $deployment->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        $this->info('Audit completed.');
        Log::channel('deploy-audit')->info('Scheduled audit of expired deployments completed.');

        return Command::SUCCESS;
    }
}
