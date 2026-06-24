<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CleanArchive extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy:clean-archive';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically clean up archived deployments older than 30 days';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting cleanup of old deployment archives...');
        Log::channel('deploy-audit')->info('Scheduled cleanup of old deployment archives started.');

        $archivePath = config('deploy.archive_path') ?? storage_path('deployments_archive');

        if (!File::isDirectory($archivePath)) {
            $this->warn('Archive directory does not exist. Skipping.');
            return Command::SUCCESS;
        }

        $directories = File::directories($archivePath);
        $deletedCount = 0;

        foreach ($directories as $dir) {
            $basename = basename($dir);
            $isOld = false;
            
            // Try parsing timestamp from directory name (e.g. clientSlug_YYYYMMDD_HHMMSS)
            if (preg_match('/_(\d{8})_\d{6}$/', $basename, $matches)) {
                $dateStr = $matches[1];
                try {
                    $archiveDate = Carbon::createFromFormat('Ymd', $dateStr);
                    if ($archiveDate->diffInDays(now()) > 30) {
                        $isOld = true;
                    }
                } catch (\Exception $e) {
                    // Ignore date parsing errors and fall back to filesystem check
                }
            }
            
            // Fallback to filesystem last modified timestamp
            if (!$isOld) {
                try {
                    $lastModified = File::lastModified($dir);
                    if (now()->subDays(30)->timestamp > $lastModified) {
                        $isOld = true;
                    }
                } catch (\Exception $e) {
                    $this->error("Failed to check modification time for: {$basename}. Error: " . $e->getMessage());
                }
            }
            
            if ($isOld) {
                $this->info("Purging archived deployment: {$basename}...");
                try {
                    File::deleteDirectory($dir);
                    $deletedCount++;
                    Log::channel('deploy-audit')->info("Cleaned up expired archive directory.", [
                        'directory' => $basename
                    ]);
                } catch (\Exception $e) {
                    $this->error("Failed to delete directory: {$basename}. Error: " . $e->getMessage());
                    Log::channel('deploy-audit')->error("Failed to clean up archive directory.", [
                        'directory' => $basename,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        $this->info("Purged {$deletedCount} old deployment archives.");
        Log::channel('deploy-audit')->info("Scheduled cleanup of old deployment archives completed. Purged: {$deletedCount}.");

        return Command::SUCCESS;
    }
}
