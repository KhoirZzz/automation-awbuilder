<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Actions\DeployServiceAction;
use App\Models\ServiceTemplate;
use App\Models\Deployment;
use App\Enums\DeploymentStatus;
use App\Enums\ServiceDuration;
use App\DataTransferObjects\LeadAnalysisResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DeployServiceActionTest extends TestCase
{
    use RefreshDatabase;

    private string $testTemplateBase;
    private string $testInstanceBase;
    private string $testArchiveBase;
    private ServiceTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testTemplateBase = storage_path('testing/templates');
        $this->testInstanceBase = storage_path('testing/instances');
        $this->testArchiveBase = storage_path('testing/archive');

        // Configure paths
        config([
            'deploy.template_base_path' => $this->testTemplateBase,
            'deploy.instance_base_path' => $this->testInstanceBase,
            'deploy.archive_path' => $this->testArchiveBase,
        ]);

        // Clear directories
        File::deleteDirectory($this->testTemplateBase);
        File::deleteDirectory($this->testInstanceBase);
        File::deleteDirectory($this->testArchiveBase);

        // Ensure directories exist
        File::makeDirectory($this->testTemplateBase, 0755, true);
        File::makeDirectory($this->testInstanceBase, 0755, true);
        File::makeDirectory($this->testArchiveBase, 0755, true);

        // Create template on filesystem
        $templatePath = $this->testTemplateBase . '/shopee-bot';
        File::makeDirectory($templatePath, 0755, true);
        File::put($templatePath . '/.env.example', "KEY=VALUE\nCLIENT_SLUG=placeholder\n");
        File::put($templatePath . '/deploy.sh', "#!/bin/bash\necho 'Deploying...'\n");

        // Save DB template
        $this->template = ServiceTemplate::create([
            'key' => 'shopee-bot',
            'name' => 'Shopee Bot',
            'template_path' => 'shopee-bot',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testTemplateBase);
        File::deleteDirectory($this->testInstanceBase);
        File::deleteDirectory($this->testArchiveBase);
        parent::tearDown();
    }

    public function test_deploy_service_action_success(): void
    {
        Process::fake([
            '*' => Process::result('Success Output', '', 0),
        ]);

        $result = new LeadAnalysisResult(
            serviceTemplateId: $this->template->id,
            duration: ServiceDuration::ONE_WEEK,
            clientSlug: 'my-bot',
            expiresAt: now()->addWeek(),
            source: 'agent',
            leadReference: 'tg_123',
            price: 100000,
            rawLlmResponse: '{"service_key":"shopee-bot"}'
        );

        $action = new DeployServiceAction();
        $deployment = $action->execute($result);

        $this->assertEquals(DeploymentStatus::ACTIVE, $deployment->status);
        $this->assertEquals($this->testInstanceBase . '/my-bot', $deployment->instance_path);

        // Check if directory cloned
        $this->assertTrue(File::isDirectory($this->testInstanceBase . '/my-bot'));
        $this->assertTrue(File::exists($this->testInstanceBase . '/my-bot/.env'));

        // Check env injection
        $env = File::get($this->testInstanceBase . '/my-bot/.env');
        $this->assertStringContainsString('CLIENT_SLUG=my-bot', $env);
        $this->assertStringContainsString('DEPLOY_EXPIRES_AT=', $env);

        // Assert process ran
        Process::assertRan(function ($process) {
            return str_contains($process->command[1], 'deploy.sh')
                && $process->command[2] === 'my-bot';
        });
    }

    public function test_deploy_service_action_pending_payment(): void
    {
        Process::fake([
            '*' => Process::result('Success Output', '', 0),
        ]);

        $result = new LeadAnalysisResult(
            serviceTemplateId: $this->template->id,
            duration: ServiceDuration::ONE_WEEK,
            clientSlug: 'my-payment-bot',
            expiresAt: now()->addWeek(),
            source: 'telegram',
            leadReference: 'tg_456',
            price: 100000,
            rawLlmResponse: '{"service_key":"shopee-bot"}'
        );

        $action = new DeployServiceAction();
        $deployment = $action->execute($result);

        $this->assertEquals(DeploymentStatus::PENDING_PAYMENT, $deployment->status);
        $this->assertTrue(File::isDirectory($this->testInstanceBase . '/my-payment-bot'));
    }

    public function test_deploy_service_action_failure_rolls_back(): void
    {
        // Script execution fails
        Process::fake([
            '*' => Process::result('Error Output', 'Failed to run script', 1),
        ]);

        $result = new LeadAnalysisResult(
            serviceTemplateId: $this->template->id,
            duration: ServiceDuration::ONE_WEEK,
            clientSlug: 'failed-bot',
            expiresAt: now()->addWeek(),
            source: 'telegram',
            leadReference: 'tg_123',
            price: null,
            rawLlmResponse: '{"service_key":"shopee-bot"}'
        );

        $action = new DeployServiceAction();

        $this->expectException(\Exception::class);

        try {
            $action->execute($result);
        } finally {
            // Assert directory was rolled back (deleted)
            $this->assertFalse(File::isDirectory($this->testInstanceBase . '/failed-bot'));

            // Assert database status set to failed
            $deployment = Deployment::where('client_slug', 'failed-bot')->first();
            $this->assertNotNull($deployment);
            $this->assertEquals(DeploymentStatus::FAILED, $deployment->status);
        }
    }
}
