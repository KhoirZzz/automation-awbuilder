<?php

namespace Tests\Feature;

use App\Enums\DeploymentStatus;
use App\Enums\ServiceDuration;
use App\Models\Deployment;
use App\Models\ServiceTemplate;
use App\Models\WebhookRequest;
use App\Support\LeadAnalysisValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NewEnhancementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::fake();
        config(['services.telegram.bot_token' => 'mock-bot-token']);
    }

    public function test_webhook_request_logging_and_deduplication(): void
    {
        // Set Telegram Bot secret
        $secretToken = 'my-tg-secret-token';
        config(['services.telegram.bot_secret_token' => $secretToken]);

        $payload = [
            'message' => [
                'message_id' => 1001,
                'chat' => ['id' => 12345],
                'text' => '/start'
            ]
        ];

        // First request - should process and log success
        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => $secretToken
        ])->postJson('/api/webhook/telegram', $payload);

        $response->assertStatus(200);

        // Verify it was logged in the database
        $this->assertDatabaseHas('webhook_requests', [
            'source' => 'telegram',
            'status_code' => 200,
            'validation_status' => 'success'
        ]);

        $log = WebhookRequest::first();
        $this->assertNotNull($log->payload_hash);

        // Second request with exact same payload - should trigger hash deduplication
        $response2 = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => $secretToken
        ])->postJson('/api/webhook/telegram', $payload);

        $response2->assertStatus(200);
        $response2->assertJson(['status' => 'received', 'duplicate' => true]);

        // Verify second request was logged as duplicate
        $this->assertDatabaseHas('webhook_requests', [
            'payload_hash' => $log->payload_hash,
            'validation_status' => 'duplicate'
        ]);
        
        $this->assertEquals(2, WebhookRequest::count());
    }

    public function test_client_api_status_endpoint(): void
    {
        $template = ServiceTemplate::create([
            'key' => 'shopee-bot',
            'name' => 'Shopee Bot',
            'template_path' => 'shopee-bot',
            'category' => 'telegram',
            'price' => 100000,
            'is_active' => true
        ]);

        $deployment = Deployment::create([
            'source' => 'telegram',
            'lead_reference' => 'tg_12345_1001',
            'service_template_id' => $template->id,
            'client_slug' => 'tokoabc',
            'instance_path' => '/tmp/deployments/tokoabc',
            'started_at' => now(),
            'expires_at' => now()->addDays(7),
            'status' => DeploymentStatus::ACTIVE,
            'price' => 100000,
            'client_token' => 'client-unique-token-123',
            'custom_domain' => 'tokoabc.my.id',
            'cpu_usage' => 1.2,
            'ram_usage' => 150.5,
            'disk_usage' => 10.4
        ]);

        // Unauthorized request (no token)
        $response = $this->getJson('/api/client/deployment/status');
        $response->assertStatus(401);

        // Unauthorized request (invalid token)
        $response = $this->getJson('/api/client/deployment/status?token=wrong-token');
        $response->assertStatus(401);

        // Successful request with token parameter
        $response = $this->getJson('/api/client/deployment/status?token=client-unique-token-123');
        $response->assertStatus(200)
            ->assertJson([
                'client_slug' => 'tokoabc',
                'status' => 'active',
                'custom_domain' => 'tokoabc.my.id',
                'resource_usage' => [
                    'cpu_usage_pct' => 1.2,
                    'ram_usage_mb' => 150.5,
                    'disk_usage_mb' => 10.4
                ]
            ]);

        // Successful request with header token
        $response = $this->withHeaders([
            'X-Client-Token' => 'client-unique-token-123'
        ])->getJson('/api/client/deployment/status');
        $response->assertStatus(200);
    }

    public function test_custom_domain_serving_via_middleware(): void
    {
        $template = ServiceTemplate::create([
            'key' => 'shopee-bot',
            'name' => 'Shopee Bot',
            'template_path' => 'shopee-bot',
            'category' => 'telegram',
            'price' => 100000,
            'is_active' => true
        ]);

        // Create temporary instance folder and index.html
        $tmpInstancePath = storage_path('test_deployments/tokoabc');
        if (!File::isDirectory($tmpInstancePath)) {
            File::makeDirectory($tmpInstancePath, 0777, true);
        }
        File::put($tmpInstancePath . '/index.html', '<h1>Hello Custom Domain</h1>');

        $deployment = Deployment::create([
            'source' => 'telegram',
            'lead_reference' => 'tg_12345_1001',
            'service_template_id' => $template->id,
            'client_slug' => 'tokoabc',
            'instance_path' => $tmpInstancePath,
            'started_at' => now(),
            'expires_at' => now()->addDays(7),
            'status' => DeploymentStatus::ACTIVE,
            'price' => 100000,
            'custom_domain' => 'tokoabc.my.id'
        ]);

        // Access via custom domain host using the absolute URL
        $response = $this->get('http://tokoabc.my.id/');

        $response->assertStatus(200);
        $response->assertSee('Hello Custom Domain');

        // Cleanup
        File::deleteDirectory(storage_path('test_deployments'));
    }

    public function test_template_preview_route(): void
    {
        $template = ServiceTemplate::create([
            'key' => 'preview-test-template',
            'name' => 'Preview Test Template',
            'template_path' => 'preview-test-template',
            'category' => 'telegram',
            'price' => 100000,
            'is_active' => true
        ]);

        // Create template index file
        $tmpTemplatePath = config('deploy.template_base_path') . '/preview-test-template';
        if (!File::isDirectory($tmpTemplatePath)) {
            File::makeDirectory($tmpTemplatePath, 0777, true);
        }
        File::put($tmpTemplatePath . '/index.html', '<h1>Template Preview Layout</h1>');

        // Call the preview route
        $response = $this->get('/templates/preview-test-template/preview');
        $response->assertStatus(200);
        $response->assertSee('Template Preview Layout');

        // Cleanup
        File::deleteDirectory($tmpTemplatePath);
    }

    public function test_pre_deploy_dns_and_version_validations(): void
    {
        $template = ServiceTemplate::create([
            'key' => 'version-test',
            'name' => 'Version Test Template',
            'template_path' => 'version-test',
            'category' => 'telegram',
            'price' => 100000,
            'is_active' => true,
            'version' => '1.0.5' // DB version is 1.0.5
        ]);

        // Scenario 1: Create a physical template with older version '1.0.0'
        $tmpTemplatePath = config('deploy.template_base_path') . '/version-test';
        if (!File::isDirectory($tmpTemplatePath)) {
            File::makeDirectory($tmpTemplatePath, 0777, true);
        }
        File::put($tmpTemplatePath . '/template.json', json_encode(['version' => '1.0.0']));

        $validator = new LeadAnalysisValidator();

        $rawResponse = [
            'service_key' => 'version-test',
            'durasi' => '1_minggu',
            'client_slug_request' => 'validslug',
            'custom_domain' => 'my-custom-domain.com'
        ];

        // Should throw validation exception due to outdated local version
        $this->expectException(\App\Exceptions\InvalidLeadAnalysisException::class);
        $this->expectExceptionMessageMatches('/is outdated/i');

        $validator->validate($rawResponse, 'telegram', 'tg_123_456');

        // Cleanup
        File::deleteDirectory($tmpTemplatePath);
    }

    public function test_expiry_reminders_and_archive_commands(): void
    {
        $template = ServiceTemplate::create([
            'key' => 'shopee-bot',
            'name' => 'Shopee Bot',
            'template_path' => 'shopee-bot',
            'category' => 'telegram',
            'price' => 100000,
            'is_active' => true
        ]);

        // Create deployment expiring in 2 days (triggering 3 days reminder)
        $deployment1 = Deployment::create([
            'source' => 'telegram',
            'lead_reference' => 'tg_88888_1001',
            'service_template_id' => $template->id,
            'client_slug' => 'slug-exp-3',
            'instance_path' => '/tmp/deployments/slug-exp-3',
            'started_at' => now()->subDays(5),
            'expires_at' => now()->addDays(2),
            'status' => DeploymentStatus::ACTIVE,
            'price' => 100000
        ]);

        // Create deployment expiring in 12 hours (triggering 1 day reminder)
        $deployment2 = Deployment::create([
            'source' => 'telegram',
            'lead_reference' => 'tg_88888_1002',
            'service_template_id' => $template->id,
            'client_slug' => 'slug-exp-1',
            'instance_path' => '/tmp/deployments/slug-exp-1',
            'started_at' => now()->subDays(6),
            'expires_at' => now()->addHours(12),
            'status' => DeploymentStatus::ACTIVE,
            'price' => 100000
        ]);

        // Run reminders command
        Artisan::call('deploy:send-expiry-reminders');

        $deployment1->refresh();
        $deployment2->refresh();

        $this->assertTrue($deployment1->reminder_3_days_sent);
        $this->assertTrue($deployment2->reminder_1_day_sent);

        // Test Archive Cleanup
        $archivePath = config('deploy.archive_path') ?? storage_path('deployments_archive');
        if (!File::isDirectory($archivePath)) {
            File::makeDirectory($archivePath, 0777, true);
        }

        // Create an old archive folder (simulating > 30 days)
        $oldArchive = $archivePath . '/oldclient_20260520_120000';
        File::makeDirectory($oldArchive, 0777, true);
        File::put($oldArchive . '/index.html', 'Old content');

        // Create a new archive folder
        $newArchive = $archivePath . '/newclient_' . now()->format('Ymd_His');
        File::makeDirectory($newArchive, 0777, true);
        File::put($newArchive . '/index.html', 'New content');

        // Run cleanup command
        Artisan::call('deploy:clean-archive');

        $this->assertDirectoryDoesNotExist($oldArchive);
        $this->assertDirectoryExists($newArchive);

        // Cleanup
        File::deleteDirectory($archivePath);
    }
}
