<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\ServiceTemplate;
use App\Models\Deployment;
use App\Enums\DeploymentStatus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AuditExpiredDeploymentsTest extends TestCase
{
    use RefreshDatabase;

    private string $testInstanceBase;
    private string $testArchiveBase;
    private ServiceTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testInstanceBase = storage_path('testing/instances');
        $this->testArchiveBase = storage_path('testing/archive');

        // Configure paths
        config([
            'deploy.instance_base_path' => $this->testInstanceBase,
            'deploy.archive_path' => $this->testArchiveBase,
        ]);

        // Clear directories
        File::deleteDirectory($this->testInstanceBase);
        File::deleteDirectory($this->testArchiveBase);

        // Ensure directories exist
        File::makeDirectory($this->testInstanceBase, 0755, true);
        File::makeDirectory($this->testArchiveBase, 0755, true);

        $this->template = ServiceTemplate::create([
            'key' => 'shopee-bot',
            'name' => 'Shopee Bot',
            'template_path' => 'shopee-bot',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testInstanceBase);
        File::deleteDirectory($this->testArchiveBase);
        parent::tearDown();
    }

    public function test_teardown_expired_deployments(): void
    {
        Process::fake([
            '*' => Process::result('Success Output', '', 0),
        ]);

        // 1. Create an active deployment that is expired
        $expiredInstancePath = $this->testInstanceBase . '/expired-client';
        File::makeDirectory($expiredInstancePath, 0755, true);
        File::put($expiredInstancePath . '/teardown.sh', "#!/bin/bash\necho 'Tearing down...'\n");

        $expiredDeployment = Deployment::create([
            'source' => 'telegram',
            'lead_reference' => 'tg_1',
            'service_template_id' => $this->template->id,
            'client_slug' => 'expired-client',
            'instance_path' => $expiredInstancePath,
            'started_at' => now()->subWeeks(2),
            'expires_at' => now()->subWeek(), // Expired 1 week ago
            'status' => DeploymentStatus::ACTIVE,
        ]);

        // 2. Create an active deployment that is NOT expired
        $activeInstancePath = $this->testInstanceBase . '/active-client';
        File::makeDirectory($activeInstancePath, 0755, true);
        File::put($activeInstancePath . '/teardown.sh', "#!/bin/bash\necho 'Tearing down...'\n");

        $activeDeployment = Deployment::create([
            'source' => 'telegram',
            'lead_reference' => 'tg_2',
            'service_template_id' => $this->template->id,
            'client_slug' => 'active-client',
            'instance_path' => $activeInstancePath,
            'started_at' => now(),
            'expires_at' => now()->addWeek(), // Expires in 1 week
            'status' => DeploymentStatus::ACTIVE,
        ]);

        // Run the artisan command
        $this->artisan('deploy:audit-expired')
            ->assertExitCode(0);

        // Assert database statuses
        $expiredDeployment->refresh();
        $activeDeployment->refresh();

        $this->assertEquals(DeploymentStatus::EXPIRED, $expiredDeployment->status);
        $this->assertEquals(DeploymentStatus::ACTIVE, $activeDeployment->status);

        // Assert process ran for the expired client
        Process::assertRan(function ($process) {
            return str_contains($process->command[1], 'teardown.sh')
                && $process->command[2] === 'expired-client';
        });

        // Assert process did NOT run for the active client
        Process::assertNotRan(function ($process) {
            return str_contains($process->command[1], 'teardown.sh')
                && $process->command[2] === 'active-client';
        });

        // Assert directory of expired client is archived and deleted from original path
        $this->assertFalse(File::isDirectory($expiredInstancePath));

        $archivedFolders = File::directories($this->testArchiveBase);
        $this->assertCount(1, $archivedFolders);
        $this->assertStringContainsString('expired-client_', $archivedFolders[0]);

        // Assert directory of active client remains untouched
        $this->assertTrue(File::isDirectory($activeInstancePath));
    }
}
