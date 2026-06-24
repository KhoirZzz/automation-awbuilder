<?php

namespace App\Console\Commands;

use App\Models\ServiceTemplate;
use App\Jobs\DeployDemoInstanceJob;
use Illuminate\Console\Command;

class DeployDemoInstancesCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'deploy:demo-instances {--sync : Run the deployment synchronously instead of dispatching to queue}';

    /**
     * The console command description.
     */
    protected $description = 'Generate and deploy demo instances for all existing service templates';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $templates = ServiceTemplate::all();
        $this->info("Found " . $templates->count() . " templates.");

        foreach ($templates as $template) {
            $clientSlug = "demo-" . $template->key;
            $this->info("Checking demo instance for: {$template->key} (Slug: {$clientSlug})");

            if ($this->option('sync')) {
                $this->info("Deploying synchronously...");
                $job = new DeployDemoInstanceJob($template);
                app()->call([$job, 'handle']);
            } else {
                $this->info("Dispatching deployment job to queue...");
                DeployDemoInstanceJob::dispatch($template);
            }
        }

        $this->info("All demo deployments have been initiated.");
    }
}
