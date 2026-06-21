<?php

namespace Tests\Feature;

use App\Jobs\ProcessManualDeployJob;
use App\Models\ServiceTemplate;
use App\Models\Deployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SandboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Cache::flush();
    }

    public function test_manual_deploy_endpoint_requires_passkey(): void
    {
        config(['deploy.agent_passkey' => '852963']);

        $response = $this->postJson('/api/dashboard/sandbox/manual-deploy', [
            'service_key' => 'gojek',
            'durasi' => '1_minggu',
            'client_slug_request' => 'testing',
            'telegram_token' => '123:ABC',
            'telegram_chat_id' => '456',
        ]);

        $response->assertStatus(401);
        Queue::assertNothingPushed();
    }

    public function test_manual_deploy_endpoint_with_valid_passkey_dispatches_job(): void
    {
        config(['deploy.agent_passkey' => '852963']);

        $response = $this->withHeaders([
            'X-Admin-Passkey' => '852963'
        ])->postJson('/api/dashboard/sandbox/manual-deploy', [
            'service_key' => 'gojek',
            'durasi' => '1_minggu',
            'client_slug_request' => 'testing',
            'telegram_token' => '123:ABC',
            'telegram_chat_id' => '456',
            'price' => '150000',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Manual deployment job queued.'
        ]);

        Queue::assertPushed(ProcessManualDeployJob::class, function (ProcessManualDeployJob $job) {
            return $job->params['service_key'] === 'gojek'
                && $job->params['durasi'] === '1_minggu'
                && $job->params['client_slug_request'] === 'testing'
                && $job->params['telegram_token'] === '123:ABC'
                && $job->params['telegram_chat_id'] === '456'
                && $job->params['price'] === '150000';
        });
    }

    public function test_manual_deploy_job_processes_successfully(): void
    {
        // 1. Create a service template
        $template = ServiceTemplate::create([
            'key' => 'gojek',
            'name' => 'Gojek Template',
            'template_path' => 'gojek',
            'is_active' => true,
        ]);

        // Mock base paths to folder in storage or a temporary folder
        config([
            'deploy.template_base_path' => storage_path('templates'),
            'deploy.instance_base_path' => storage_path('deployments_test'),
        ]);

        // Create the dummy template directory
        \Illuminate\Support\Facades\File::makeDirectory(storage_path('templates/gojek'), 0755, true, true);
        \Illuminate\Support\Facades\File::put(storage_path('templates/gojek/.env.example'), "CLIENT_SLUG=\nDEPLOY_EXPIRES_AT=\nDEPLOY_STARTED_AT=");

        $params = [
            'service_key' => 'gojek',
            'durasi' => '1_minggu',
            'client_slug_request' => 'mytestslug',
            'telegram_token' => '1234:ABC',
            'telegram_chat_id' => '9876',
            'price' => '120000'
        ];
        $leadRef = 'sandbox_manual_test_123';

        // Run the job handler directly
        $job = new ProcessManualDeployJob($params, $leadRef);
        $deployAction = $this->app->make(\App\Actions\DeployServiceAction::class);
        $job->handle($deployAction);

        // Assert Cache statuses are set
        $this->assertEquals('completed', Cache::get("sandbox_status_{$leadRef}")['stage']);
        $this->assertEquals('active', Cache::get("sandbox_status_{$leadRef}")['status']);

        // Assert deployment database entry exists
        $this->assertDatabaseHas('deployments', [
            'lead_reference' => $leadRef,
            'client_slug' => 'mytestslug',
            'status' => \App\Enums\DeploymentStatus::ACTIVE->value,
            'price' => 120000
        ]);

        // Clean up directories
        \Illuminate\Support\Facades\File::deleteDirectory(storage_path('templates/gojek'));
        \Illuminate\Support\Facades\File::deleteDirectory(storage_path('deployments_test'));
    }

    public function test_manual_deploy_overwrites_existing_directory(): void
    {
        // 1. Create a service template
        $template = ServiceTemplate::create([
            'key' => 'gojek',
            'name' => 'Gojek Template',
            'template_path' => 'gojek',
            'is_active' => true,
        ]);

        // Mock base paths
        config([
            'deploy.template_base_path' => storage_path('templates'),
            'deploy.instance_base_path' => storage_path('deployments_test'),
        ]);

        // Create the dummy template directory
        \Illuminate\Support\Facades\File::makeDirectory(storage_path('templates/gojek'), 0755, true, true);
        \Illuminate\Support\Facades\File::put(storage_path('templates/gojek/.env.example'), "CLIENT_SLUG=\nDEPLOY_EXPIRES_AT=\nDEPLOY_STARTED_AT=");

        // Pre-create the instance deployment directory with collision files
        $collidingPath = storage_path('deployments_test/mytestslug');
        \Illuminate\Support\Facades\File::makeDirectory($collidingPath, 0755, true, true);
        \Illuminate\Support\Facades\File::put($collidingPath . '/stale.txt', 'stale text');

        $params = [
            'service_key' => 'gojek',
            'durasi' => '1_minggu',
            'client_slug_request' => 'mytestslug',
            'telegram_token' => '1234:ABC',
            'telegram_chat_id' => '9876',
            'price' => '120000'
        ];
        $leadRef = 'sandbox_manual_test_collision';

        // Run the job handler directly
        $job = new ProcessManualDeployJob($params, $leadRef);
        $deployAction = $this->app->make(\App\Actions\DeployServiceAction::class);
        $job->handle($deployAction);

        // Assert Cache statuses are set
        $this->assertEquals('completed', Cache::get("sandbox_status_{$leadRef}")['stage']);
        $this->assertEquals('active', Cache::get("sandbox_status_{$leadRef}")['status']);

        // Assert collision file no longer exists and new deployment files are set
        $this->assertFileDoesNotExist($collidingPath . '/stale.txt');
        $this->assertFileExists($collidingPath . '/.env');

        // Assert deployment database entry exists
        $this->assertDatabaseHas('deployments', [
            'lead_reference' => $leadRef,
            'client_slug' => 'mytestslug',
            'status' => \App\Enums\DeploymentStatus::ACTIVE->value
        ]);

        // Clean up directories
        \Illuminate\Support\Facades\File::deleteDirectory(storage_path('templates/gojek'));
        \Illuminate\Support\Facades\File::deleteDirectory(storage_path('deployments_test'));
    }

    public function test_reserved_subdomains_behavior(): void
    {
        config(['app.url' => 'https://mockbuild.shop']);

        // admin.mockbuild.shop should serve welcome (200 OK)
        $responseAdmin = $this->get('http://admin.mockbuild.shop/');
        $responseAdmin->assertStatus(200);

        // www.mockbuild.shop should serve welcome (200 OK)
        $responseWww = $this->get('http://www.mockbuild.shop/');
        $responseWww->assertStatus(200);

        // mail.mockbuild.shop should return 404
        $responseMail = $this->get('http://mail.mockbuild.shop/');
        $responseMail->assertStatus(404);
    }

    public function test_public_templates_endpoint_returns_active_templates(): void
    {
        // Create an active template
        ServiceTemplate::create([
            'key' => 'shopee-bot-active',
            'name' => 'Shopee Bot Active',
            'template_path' => 'shopee-bot',
            'is_active' => true
        ]);

        // Create an inactive template
        ServiceTemplate::create([
            'key' => 'shopee-bot-inactive',
            'name' => 'Shopee Bot Inactive',
            'template_path' => 'shopee-bot',
            'is_active' => false
        ]);

        $response = $this->getJson('/api/public/templates');

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'key' => 'shopee-bot-active',
            'name' => 'Shopee Bot Active',
        ]);
        $response->assertJsonMissing([
            'key' => 'shopee-bot-inactive',
        ]);
    }

    public function test_public_deploy_endpoint_creates_pending_payment_deployment(): void
    {
        // 1. Create a service template
        ServiceTemplate::create([
            'key' => 'gojek',
            'name' => 'Gojek Template',
            'template_path' => 'gojek',
            'is_active' => true,
        ]);

        // Mock base paths
        config([
            'deploy.template_base_path' => storage_path('templates'),
            'deploy.instance_base_path' => storage_path('deployments_test'),
            'deploy.agent_passkey' => '852963'
        ]);

        // Create the dummy template directory
        \Illuminate\Support\Facades\File::makeDirectory(storage_path('templates/gojek'), 0755, true, true);
        \Illuminate\Support\Facades\File::put(storage_path('templates/gojek/.env.example'), "CLIENT_SLUG=\nDEPLOY_EXPIRES_AT=\nDEPLOY_STARTED_AT=");

        $response = $this->postJson('/api/public/deploy', [
            'service_key' => 'gojek',
            'durasi' => '1_bulan',
            'client_slug_request' => 'pubslugtest',
            'telegram_token' => '999:DEF',
            'telegram_chat_id' => '111',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'price' => null
        ]);

        // Assert deployment database entry exists as pending_payment
        $this->assertDatabaseHas('deployments', [
            'client_slug' => 'pubslugtest',
            'status' => \App\Enums\DeploymentStatus::PENDING_PAYMENT->value,
            'price' => null
        ]);

        // Clean up directories
        \Illuminate\Support\Facades\File::deleteDirectory(storage_path('templates/gojek'));
        \Illuminate\Support\Facades\File::deleteDirectory(storage_path('deployments_test'));
    }
}
