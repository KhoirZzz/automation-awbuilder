<?php

namespace App\Console\Commands;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class MonitorResources extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy:monitor-resources';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor and record VPS resource usage (Disk, CPU, RAM) for all active deployments';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting resource usage monitoring for active deployments...');
        Log::channel('deploy-audit')->info('Scheduled resource usage monitoring started.');

        $activeDeployments = Deployment::where('status', DeploymentStatus::ACTIVE)->get();
        $this->info("Found {$activeDeployments->count()} active deployments to monitor.");

        // Fetch process list once to analyze for all deployments
        $psProcess = Process::run(['ps', '-eo', 'pcpu,rss,args']);
        $psLines = [];
        if ($psProcess->successful()) {
            $psLines = explode("\n", $psProcess->output());
        } else {
            $this->error('Failed to run ps command.');
            Log::channel('deploy-audit')->error('Resource monitoring failed: ps command execution error.');
        }

        $monitoredCount = 0;

        foreach ($activeDeployments as $deployment) {
            $instancePath = $deployment->instance_path;
            $clientSlug = $deployment->client_slug;

            if (!File::isDirectory($instancePath)) {
                $this->warn("Instance directory for slug '{$clientSlug}' does not exist. Skipping resource check.");
                continue;
            }

            // 1. Calculate Disk Space in MB
            $diskUsageMb = 0.0;
            $duProcess = Process::run(['du', '-s', $instancePath]);
            if ($duProcess->successful()) {
                $parts = preg_split('/\s+/', trim($duProcess->output()));
                if (isset($parts[0]) && is_numeric($parts[0])) {
                    $diskUsageMb = round((int)$parts[0] / 1024, 2); // Convert KB to MB
                }
            }

            // 2. Calculate CPU and RAM (RSS) usage of processes
            $cpuPercent = 0.0;
            $ramUsageMb = 0.0;

            foreach ($psLines as $line) {
                $line = trim($line);
                if (empty($line)) continue;

                // Match processes containing the client slug or instance path in command line arguments
                if (str_contains($line, $clientSlug) || str_contains($line, $instancePath)) {
                    $parts = preg_split('/\s+/', $line, 3);
                    if (count($parts) >= 3) {
                        $cpuPercent += (float)$parts[0];
                        $ramUsageMb += (int)$parts[1] / 1024; // Convert KB to MB
                    }
                }
            }

            // Save metrics to DB
            $deployment->update([
                'cpu_usage' => round($cpuPercent, 2),
                'ram_usage' => round($ramUsageMb, 2),
                'disk_usage' => round($diskUsageMb, 2),
                'last_monitored_at' => now(),
            ]);

            $this->info("Monitored [{$clientSlug}]: CPU: {$cpuPercent}%, RAM: {$ramUsageMb}MB, Disk: {$diskUsageMb}MB");
            $monitoredCount++;
        }

        // Write aggregate stats to audit log
        Log::channel('deploy-audit')->info('Resource usage monitoring completed.', [
            'monitored_instances_count' => $monitoredCount,
            'timestamp' => now()->toIso8601String()
        ]);

        $this->info("Resource monitoring completed for {$monitoredCount} instances.");
        return Command::SUCCESS;
    }
}
