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

    public function test_client_subdomain_php_execution(): void
    {
        config(['app.url' => 'https://mockbuild.shop']);

        // Mock base paths
        config([
            'deploy.template_base_path' => storage_path('templates'),
            'deploy.instance_base_path' => storage_path('deployments_test'),
        ]);

        $instancePath = storage_path('deployments_test/client-test-slug');
        \Illuminate\Support\Facades\File::makeDirectory($instancePath, 0755, true, true);
        
        \Illuminate\Support\Facades\File::put($instancePath . '/hello.php', '<?php echo "Hello PHP World";');

        // Create a dummy template
        $template = ServiceTemplate::create([
            'key' => 'gojek-test',
            'name' => 'Gojek Test Template',
            'template_path' => 'gojek',
            'is_active' => true,
        ]);

        // Create active deployment in DB
        Deployment::create([
            'source' => 'telegram',
            'service_template_id' => $template->id,
            'lead_reference' => 'test_lead_php',
            'client_slug' => 'client-test-slug',
            'status' => \App\Enums\DeploymentStatus::ACTIVE->value,
            'instance_path' => $instancePath,
            'started_at' => now(),
            'expires_at' => now()->addWeek(),
        ]);

        $response = $this->get('http://client-test-slug.mockbuild.shop/hello.php');
        $response->assertStatus(200);
        $response->assertSee('Hello PHP World');

        // Clean up
        \Illuminate\Support\Facades\File::deleteDirectory(storage_path('deployments_test'));
    }

    public function test_client_subdomain_php_execution_with_redirect_and_headers(): void
    {
        config(['app.url' => 'https://mockbuild.shop']);

        // Mock base paths
        config([
            'deploy.template_base_path' => storage_path('templates'),
            'deploy.instance_base_path' => storage_path('deployments_test'),
        ]);

        $instancePath = storage_path('deployments_test/client-test-slug-redirect');
        \Illuminate\Support\Facades\File::makeDirectory($instancePath, 0755, true, true);
        
        \Illuminate\Support\Facades\File::put(
            $instancePath . '/redirect.php', 
            '<?php header("Location: /target.php"); http_response_code(302); echo "Redirecting...";'
        );

        // Create a dummy template
        $template = ServiceTemplate::create([
            'key' => 'gojek-test-2',
            'name' => 'Gojek Test Template 2',
            'template_path' => 'gojek',
            'is_active' => true,
        ]);

        // Create active deployment in DB
        Deployment::create([
            'source' => 'telegram',
            'service_template_id' => $template->id,
            'lead_reference' => 'test_lead_php_redirect',
            'client_slug' => 'client-test-slug-redirect',
            'status' => \App\Enums\DeploymentStatus::ACTIVE->value,
            'instance_path' => $instancePath,
            'started_at' => now(),
            'expires_at' => now()->addWeek(),
        ]);

        $response = $this->get('http://client-test-slug-redirect.mockbuild.shop/redirect.php');
        
        // Assert we got status code 302 and the response body
        $response->assertStatus(302);
        $response->assertSee('Redirecting...');

        // Clean up
        \Illuminate\Support\Facades\File::deleteDirectory(storage_path('deployments_test'));
    }

    public function test_download_pdf_by_slug_active(): void
    {
        config(['app.url' => 'https://mockbuild.shop']);
        config(['deploy.instance_base_path' => storage_path('deployments_test')]);

        $instancePath = storage_path('deployments_test/my-active-slug');
        \Illuminate\Support\Facades\File::makeDirectory($instancePath, 0755, true, true);
        \Illuminate\Support\Facades\File::put($instancePath . '/shopee-16.pdf', 'dummy pdf content');

        $template = ServiceTemplate::create([
            'key' => 'gojek-test-3',
            'name' => 'Gojek Test Template 3',
            'template_path' => 'gojek',
            'is_active' => true,
        ]);

        Deployment::create([
            'source' => 'telegram',
            'service_template_id' => $template->id,
            'lead_reference' => 'test_lead_pdf_active',
            'client_slug' => 'my-active-slug',
            'status' => \App\Enums\DeploymentStatus::ACTIVE->value,
            'instance_path' => $instancePath,
            'started_at' => now(),
            'expires_at' => now()->addWeek(),
        ]);

        $response = $this->get('https://mockbuild.shop/my-active-slug');
        $response->assertStatus(200);
        $response->assertHeader('Content-Disposition', 'attachment; filename=shopee-16.pdf');
        $this->assertEquals('dummy pdf content', $response->streamedContent());

        \Illuminate\Support\Facades\File::deleteDirectory(storage_path('deployments_test'));
    }

    public function test_download_pdf_by_slug_pending(): void
    {
        config(['app.url' => 'https://mockbuild.shop']);
        config(['deploy.instance_base_path' => storage_path('deployments_test')]);

        $instancePath = storage_path('deployments_test/my-pending-slug');
        \Illuminate\Support\Facades\File::makeDirectory($instancePath, 0755, true, true);
        \Illuminate\Support\Facades\File::put($instancePath . '/shopee-16.pdf', 'dummy pdf content');

        $template = ServiceTemplate::create([
            'key' => 'gojek-test-4',
            'name' => 'Gojek Test Template 4',
            'template_path' => 'gojek',
            'is_active' => true,
        ]);

        $deployment = Deployment::create([
            'source' => 'telegram',
            'service_template_id' => $template->id,
            'lead_reference' => 'test_lead_pdf_pending',
            'client_slug' => 'my-pending-slug',
            'status' => \App\Enums\DeploymentStatus::PENDING_PAYMENT->value,
            'instance_path' => $instancePath,
            'started_at' => now(),
            'expires_at' => now()->addWeek(),
            'price' => 150000,
        ]);

        $response = $this->get('https://mockbuild.shop/my-pending-slug');
        $response->assertStatus(200);
        $response->assertViewIs('pending_download');
        $response->assertSee('Persetujuan Tertunda');
        $response->assertSee('my-pending-slug');

        \Illuminate\Support\Facades\File::deleteDirectory(storage_path('deployments_test'));
    }

    public function test_download_pdf_by_slug_with_passkey(): void
    {
        config(['app.url' => 'https://mockbuild.shop']);
        config([
            'deploy.instance_base_path' => storage_path('deployments_test'),
            'deploy.agent_passkey' => '051205'
        ]);

        $instancePath = storage_path('deployments_test/my-admin-slug');
        \Illuminate\Support\Facades\File::makeDirectory($instancePath, 0755, true, true);
        \Illuminate\Support\Facades\File::put($instancePath . '/shopee-16.pdf', 'admin dummy pdf content');

        $template = ServiceTemplate::create([
            'key' => 'gojek-test-5',
            'name' => 'Gojek Test Template 5',
            'template_path' => 'gojek',
            'is_active' => true,
        ]);

        Deployment::create([
            'source' => 'telegram',
            'service_template_id' => $template->id,
            'lead_reference' => 'test_lead_pdf_admin',
            'client_slug' => 'my-admin-slug',
            'status' => \App\Enums\DeploymentStatus::PENDING_PAYMENT->value,
            'instance_path' => $instancePath,
            'started_at' => now(),
            'expires_at' => now()->addWeek(),
        ]);

        // Accessing with admin passkey should bypass the active check and allow downloading
        $response = $this->get('https://mockbuild.shop/my-admin-slug?passkey=051205');
        $response->assertStatus(200);
        $response->assertHeader('Content-Disposition', 'attachment; filename=shopee-16.pdf');
        $this->assertEquals('admin dummy pdf content', $response->streamedContent());

        \Illuminate\Support\Facades\File::deleteDirectory(storage_path('deployments_test'));
    }

    public function test_credential_injection_supports_all_variants(): void
    {
        // 1. Create a service template
        $template = ServiceTemplate::create([
            'key' => 'variant-test',
            'name' => 'Variant Test Template',
            'template_path' => 'variant-test',
            'is_active' => true,
        ]);

        // Mock base paths
        config([
            'deploy.template_base_path' => storage_path('templates'),
            'deploy.instance_base_path' => storage_path('deployments_test'),
        ]);

        // Create the dummy template directory
        $templatePath = storage_path('templates/variant-test');
        \Illuminate\Support\Facades\File::makeDirectory($templatePath, 0755, true, true);
        \Illuminate\Support\Facades\File::put($templatePath . '/.env.example', "CLIENT_SLUG=\nDEPLOY_EXPIRES_AT=\nDEPLOY_STARTED_AT=");

        // Create files with variable variants to test
        $sendPhpContent = <<<'PHP'
<?php
$token = "8845353255:AAGIuMCSDULKf-xZxzCvWpkYSA3ldn1IJLg";
$chatid = "8271767464";
$id_bot = "7726129945:AAHmQX-ZIYUZW7uusWowPlLpMBAu_yVfUPs";
$telegram_id = "6924161299";
PHP;
        \Illuminate\Support\Facades\File::makeDirectory($templatePath . '/config', 0755, true, true);
        \Illuminate\Support\Facades\File::put($templatePath . '/config/send.php', $sendPhpContent);

        $jsContent = <<<'JS'
const telegramToken = "8410052280:AAH0EheI6IC5K9l756g23aoJvG7W8ixh974";
const telegramChatId = "8114987077";
const chat_id = '6448499533', botID = 'bot7233795732:AAH1V1cdxWfGrFZ8bbIi9DBWwUEvZE6TJ3g';
JS;
        \Illuminate\Support\Facades\File::put($templatePath . '/token.js', $jsContent);

        $params = [
            'service_key' => 'variant-test',
            'durasi' => '1_minggu',
            'client_slug_request' => 'variant-slug',
            'telegram_token' => '11111:NEW_TOKEN_AAA_BBB',
            'telegram_chat_id' => '22222',
            'price' => '100000'
        ];
        $leadRef = 'sandbox_manual_test_variants';

        $job = new ProcessManualDeployJob($params, $leadRef);
        $deployAction = $this->app->make(\App\Actions\DeployServiceAction::class);
        $job->handle($deployAction);

        // Assert files in instance path have replaced values
        $instancePath = storage_path('deployments_test/variant-slug');
        $this->assertFileExists($instancePath . '/config/send.php');
        $this->assertFileExists($instancePath . '/token.js');

        $sendPhpResult = \Illuminate\Support\Facades\File::get($instancePath . '/config/send.php');
        $this->assertStringContainsString('$token = "11111:NEW_TOKEN_AAA_BBB";', $sendPhpResult);
        $this->assertStringContainsString('$chatid = "22222";', $sendPhpResult);
        $this->assertStringContainsString('$id_bot = "11111:NEW_TOKEN_AAA_BBB";', $sendPhpResult);
        $this->assertStringContainsString('$telegram_id = "22222";', $sendPhpResult);

        $jsResult = \Illuminate\Support\Facades\File::get($instancePath . '/token.js');
        $this->assertStringContainsString('const telegramToken = "11111:NEW_TOKEN_AAA_BBB";', $jsResult);
        $this->assertStringContainsString('const telegramChatId = "22222";', $jsResult);
        $this->assertStringContainsString("const chat_id = '22222'", $jsResult);
        $this->assertStringContainsString("botID = 'bot11111:NEW_TOKEN_AAA_BBB'", $jsResult);

        // Clean up
        \Illuminate\Support\Facades\File::deleteDirectory(storage_path('templates/variant-test'));
        \Illuminate\Support\Facades\File::deleteDirectory(storage_path('deployments_test'));
    }
}
