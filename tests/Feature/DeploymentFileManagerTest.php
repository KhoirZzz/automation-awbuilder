<?php

namespace Tests\Feature;

use App\Models\Deployment;
use App\Models\ServiceTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DeploymentFileManagerTest extends TestCase
{
    use RefreshDatabase;

    private string $deploymentBaseDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deploymentBaseDir = storage_path('testing/deployments');
        config([
            'deploy.agent_passkey' => '852963'
        ]);

        if (File::isDirectory($this->deploymentBaseDir)) {
            File::deleteDirectory($this->deploymentBaseDir);
        }
        File::makeDirectory($this->deploymentBaseDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->deploymentBaseDir)) {
            File::deleteDirectory($this->deploymentBaseDir);
        }
        parent::tearDown();
    }

    private function createMockDeployment(string $slug): Deployment
    {
        $instancePath = $this->deploymentBaseDir . '/' . $slug;
        File::makeDirectory($instancePath, 0775, true);
        File::put($instancePath . '/index.php', '<?php echo "Hello";');
        File::makeDirectory($instancePath . '/css', 0775, true);
        File::put($instancePath . '/css/style.css', 'body {}');

        // Create DB record
        $template = ServiceTemplate::create([
            'key' => 'test-template',
            'name' => 'Test Template',
            'template_path' => 'test-template',
            'is_active' => true
        ]);

        return Deployment::create([
            'source' => 'telegram',
            'service_template_id' => $template->id,
            'client_name' => 'Test Client',
            'client_slug' => $slug,
            'instance_path' => $instancePath,
            'status' => 'active',
            'started_at' => now(),
            'expires_at' => now()->addWeek(),
            'price' => 50000,
        ]);
    }

    public function test_list_deployment_files(): void
    {
        $deployment = $this->createMockDeployment('client-test');

        $response = $this->getJson('/api/dashboard/deployments/files?client_slug=client-test', [
            'X-Admin-Passkey' => '852963'
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['name' => 'index.php', 'is_dir' => false]);
        $response->assertJsonFragment(['name' => 'css', 'is_dir' => true]);
    }

    public function test_get_deployment_file_content(): void
    {
        $deployment = $this->createMockDeployment('client-test');

        $response = $this->getJson('/api/dashboard/deployments/file/content?client_slug=client-test&path=index.php', [
            'X-Admin-Passkey' => '852963'
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'path' => 'index.php',
            'content' => '<?php echo "Hello";'
        ]);
    }

    public function test_create_deployment_file_or_folder(): void
    {
        $deployment = $this->createMockDeployment('client-test');

        // Create file
        $response = $this->postJson('/api/dashboard/deployments/file', [
            'client_slug' => 'client-test',
            'path' => 'test.txt',
            'is_dir' => false,
            'content' => 'Sample content'
        ], [
            'X-Admin-Passkey' => '852963'
        ]);

        $response->assertStatus(200);
        $this->assertFileExists($deployment->instance_path . '/test.txt');
        $this->assertEquals('Sample content', File::get($deployment->instance_path . '/test.txt'));

        // Create folder
        $response = $this->postJson('/api/dashboard/deployments/file', [
            'client_slug' => 'client-test',
            'path' => 'images',
            'is_dir' => true
        ], [
            'X-Admin-Passkey' => '852963'
        ]);

        $response->assertStatus(200);
        $this->assertDirectoryExists($deployment->instance_path . '/images');
    }

    public function test_update_deployment_file_content(): void
    {
        $deployment = $this->createMockDeployment('client-test');

        $response = $this->putJson('/api/dashboard/deployments/file', [
            'client_slug' => 'client-test',
            'path' => 'index.php',
            'content' => '<?php echo "New Hello";'
        ], [
            'X-Admin-Passkey' => '852963'
        ]);

        $response->assertStatus(200);
        $this->assertEquals('<?php echo "New Hello";', File::get($deployment->instance_path . '/index.php'));
    }

    public function test_delete_deployment_file_or_folder(): void
    {
        $deployment = $this->createMockDeployment('client-test');

        $response = $this->deleteJson('/api/dashboard/deployments/file', [
            'client_slug' => 'client-test',
            'path' => 'css/style.css'
        ], [
            'X-Admin-Passkey' => '852963'
        ]);

        $response->assertStatus(200);
        $this->assertFileDoesNotExist($deployment->instance_path . '/css/style.css');
    }

    public function test_rename_deployment_file_or_folder(): void
    {
        $deployment = $this->createMockDeployment('client-test');

        // Rename file
        $response = $this->postJson('/api/dashboard/deployments/file/rename', [
            'client_slug' => 'client-test',
            'path' => 'index.php',
            'new_name' => 'home.php'
        ], [
            'X-Admin-Passkey' => '852963'
        ]);

        $response->assertStatus(200);
        $this->assertFileExists($deployment->instance_path . '/home.php');
        $this->assertFileDoesNotExist($deployment->instance_path . '/index.php');

        // Rename folder
        $response = $this->postJson('/api/dashboard/deployments/file/rename', [
            'client_slug' => 'client-test',
            'path' => 'css',
            'new_name' => 'styles'
        ], [
            'X-Admin-Passkey' => '852963'
        ]);

        $response->assertStatus(200);
        $this->assertDirectoryExists($deployment->instance_path . '/styles');
        $this->assertDirectoryDoesNotExist($deployment->instance_path . '/css');
    }
}
