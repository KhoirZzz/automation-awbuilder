<?php

namespace Tests\Feature;

use App\Models\ServiceTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TemplateZipTest extends TestCase
{
    use RefreshDatabase;

    private string $uploadDir;
    private string $templateDir;
    private string $instanceDir;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->uploadDir = storage_path('app/uploads');
        $this->templateDir = storage_path('testing/layanan');
        $this->instanceDir = storage_path('testing/instances');

        config([
            'deploy.template_base_path' => $this->templateDir,
            'deploy.instance_base_path' => $this->instanceDir,
            'deploy.agent_passkey' => '852963'
        ]);

        if (File::isDirectory($this->uploadDir)) {
            File::deleteDirectory($this->uploadDir);
        }
        if (File::isDirectory($this->templateDir)) {
            File::deleteDirectory($this->templateDir);
        }
        if (File::isDirectory($this->instanceDir)) {
            File::deleteDirectory($this->instanceDir);
        }
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->uploadDir)) {
            File::deleteDirectory($this->uploadDir);
        }
        if (File::isDirectory($this->templateDir)) {
            File::deleteDirectory($this->templateDir);
        }
        if (File::isDirectory($this->instanceDir)) {
            File::deleteDirectory($this->instanceDir);
        }
        parent::tearDown();
    }

    public function test_upload_zip_endpoint(): void
    {
        // Mock a zip file
        $file = UploadedFile::fake()->create('test-template.zip', 100, 'application/zip');

        $response = $this->postJson('/api/dashboard/templates/upload-zip', [
            'zip_file' => $file,
        ], [
            'X-Admin-Passkey' => '852963'
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'original_name' => 'test-template.zip',
        ]);

        $filename = $response->json('filename');
        $this->assertFileExists(storage_path('app/uploads/' . $filename));
    }

    public function test_list_zips_endpoint(): void
    {
        File::makeDirectory($this->uploadDir, 0755, true);
        File::put($this->uploadDir . '/archive1.zip', 'content');
        File::put($this->uploadDir . '/archive2.zip', 'content');
        File::put($this->uploadDir . '/not-a-zip.txt', 'content');

        $response = $this->getJson('/api/dashboard/templates/zips', [
            'X-Admin-Passkey' => '852963'
        ]);

        $response->assertStatus(200);
        $response->assertJsonCount(2);
        $response->assertJsonFragment(['filename' => 'archive1.zip']);
        $response->assertJsonFragment(['filename' => 'archive2.zip']);
    }

    public function test_extract_zip_endpoint_success(): void
    {
        // 1. Create a real tiny ZIP file for extraction test
        File::makeDirectory($this->uploadDir, 0755, true);
        $zipPath = $this->uploadDir . '/test-extract.zip';
        
        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE) === TRUE) {
            $zip->addFromString('index.html', '<h1>Hello World</h1>');
            $zip->addFromString('config/send.php', '<?php $service_token = "old"; $service_chat = "old";');
            $zip->close();
        }

        // 2. Call the extract API
        $response = $this->postJson('/api/dashboard/templates/extract-zip', [
            'filename' => 'test-extract.zip',
            'key' => 'my-custom-service',
            'name' => 'My Custom Service Name'
        ], [
            'X-Admin-Passkey' => '852963'
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Template extracted and registered successfully.'
        ]);

        // 3. Verify files were extracted
        $this->assertDirectoryExists($this->templateDir . '/my-custom-service');
        $this->assertFileExists($this->templateDir . '/my-custom-service/index.html');
        $this->assertFileExists($this->templateDir . '/my-custom-service/config/send.php');

        // 4. Verify template registered in DB
        $this->assertDatabaseHas('service_templates', [
            'key' => 'my-custom-service',
            'name' => 'My Custom Service Name',
            'template_path' => 'my-custom-service'
        ]);

        // 5. Verify upload file is deleted
        $this->assertFileDoesNotExist($zipPath);
    }

    public function test_extract_zip_validation_rules(): void
    {
        $response = $this->postJson('/api/dashboard/templates/extract-zip', [
            'filename' => 'nonexistent.zip',
            'key' => 'INVALID KEY!##',
            'name' => 'Test'
        ], [
            'X-Admin-Passkey' => '852963'
        ]);

        $response->assertStatus(422); // Validation error
    }

    public function test_delete_template_success(): void
    {
        // 1. Create a template record
        $template = ServiceTemplate::create([
            'key' => 'delete-me',
            'name' => 'Delete Me Template',
            'template_path' => 'delete-me-folder',
            'is_active' => true
        ]);

        // 2. Create the dummy template folder on disk
        $folderPath = $this->templateDir . '/delete-me-folder';
        File::makeDirectory($folderPath, 0755, true);
        File::put($folderPath . '/index.html', 'content');

        // 3. Make the API delete request
        $response = $this->deleteJson('/api/dashboard/templates/' . $template->id, [], [
            'X-Admin-Passkey' => '852963'
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Template berhasil dihapus.'
        ]);

        // 4. Verify template removed from DB
        $this->assertDatabaseMissing('service_templates', [
            'id' => $template->id
        ]);

        // 5. Verify folder deleted on disk
        $this->assertDirectoryDoesNotExist($folderPath);
    }

    public function test_delete_template_fails_if_has_deployments(): void
    {
        // 1. Create template
        $template = ServiceTemplate::create([
            'key' => 'keep-me',
            'name' => 'Keep Me Template',
            'template_path' => 'keep-me-folder',
            'is_active' => true
        ]);

        // 2. Create associated deployment
        \App\Models\Deployment::create([
            'source' => 'telegram',
            'lead_reference' => '9999',
            'service_template_id' => $template->id,
            'client_slug' => 'active-slug',
            'instance_path' => storage_path('app/deployments/active-slug'),
            'started_at' => now(),
            'expires_at' => now()->addWeek(),
            'status' => \App\Enums\DeploymentStatus::ACTIVE,
            'price' => 50000,
        ]);

        // 3. Request deletion
        $response = $this->deleteJson('/api/dashboard/templates/' . $template->id, [], [
            'X-Admin-Passkey' => '852963'
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'error' => 'Template tidak dapat dihapus karena memiliki riwayat/aktif deployment.'
        ]);

        // 4. Verify template still in DB
        $this->assertDatabaseHas('service_templates', [
            'id' => $template->id
        ]);
    }

    public function test_delete_template_success_with_force(): void
    {
        // 1. Create template
        $template = ServiceTemplate::create([
            'key' => 'force-delete-me',
            'name' => 'Force Delete Me Template',
            'template_path' => 'force-delete-folder',
            'is_active' => true
        ]);

        // 2. Create template directory
        $folderPath = $this->templateDir . '/force-delete-folder';
        File::makeDirectory($folderPath, 0755, true);
        File::put($folderPath . '/index.html', 'dummy index');

        // 3. Create associated deployment
        $instancePath = storage_path('app/deployments/force-active-slug');
        File::makeDirectory($instancePath, 0755, true);
        File::put($instancePath . '/index.html', 'dummy deploy');

        $deployment = \App\Models\Deployment::create([
            'source' => 'telegram',
            'lead_reference' => '9999-force',
            'service_template_id' => $template->id,
            'client_slug' => 'force-active-slug',
            'instance_path' => $instancePath,
            'started_at' => now(),
            'expires_at' => now()->addWeek(),
            'status' => \App\Enums\DeploymentStatus::ACTIVE,
            'price' => 50000,
        ]);

        // 4. Request deletion with force=true
        $response = $this->deleteJson('/api/dashboard/templates/' . $template->id . '?force=true', [], [
            'X-Admin-Passkey' => '852963'
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Template dan seluruh riwayat deployment terkait berhasil dihapus.'
        ]);

        // 5. Verify template and deployment removed from DB
        $this->assertDatabaseMissing('service_templates', [
            'id' => $template->id
        ]);
        $this->assertDatabaseMissing('deployments', [
            'id' => $deployment->id
        ]);

        // 6. Verify folders deleted on disk
        $this->assertDirectoryDoesNotExist($folderPath);
        $this->assertDirectoryDoesNotExist($instancePath);
    }

    public function test_delete_zip_success(): void
    {
        // 1. Create a dummy zip file in the upload directory
        File::makeDirectory($this->uploadDir, 0755, true);
        $zipPath = $this->uploadDir . '/delete-test.zip';
        File::put($zipPath, 'dummy content');

        $this->assertFileExists($zipPath);

        // 2. Call the delete API
        $response = $this->deleteJson('/api/dashboard/templates/zips/delete-test.zip', [], [
            'X-Admin-Passkey' => '852963'
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'File ZIP berhasil dihapus.'
        ]);

        // 3. Verify file is deleted
        $this->assertFileDoesNotExist($zipPath);
    }

    public function test_delete_zip_not_found(): void
    {
        $response = $this->deleteJson('/api/dashboard/templates/zips/nonexistent-delete.zip', [], [
            'X-Admin-Passkey' => '852963'
        ]);

        $response->assertStatus(404);
        $response->assertJson([
            'error' => 'File ZIP tidak ditemukan.'
        ]);
    }

    public function test_list_files_includes_hidden_files(): void
    {
        // 1. Create template
        $template = ServiceTemplate::create([
            'key' => 'list-files-test',
            'name' => 'List Files Test',
            'template_path' => 'list-files-folder',
            'is_active' => true
        ]);

        // 2. Create template directory with files, subfolders, and hidden files
        $folderPath = $this->templateDir . '/list-files-folder';
        File::makeDirectory($folderPath, 0755, true);
        File::put($folderPath . '/index.html', 'dummy index');
        File::put($folderPath . '/.htaccess', 'dummy htaccess');
        File::put($folderPath . '/imm.php', 'dummy php');
        File::makeDirectory($folderPath . '/config', 0755, true);

        // 3. Request listing
        $response = $this->getJson('/api/dashboard/templates/files?template_key=list-files-test', [
            'X-Admin-Passkey' => '852963'
        ]);

        $response->assertStatus(200);
        $response->assertJsonCount(4); // index.html, .htaccess, imm.php, config
        $response->assertJsonFragment(['name' => '.htaccess']);
        $response->assertJsonFragment(['name' => 'imm.php']);
    }

    public function test_automated_demo_deployment_via_job(): void
    {
        // 1. Create a template
        $template = ServiceTemplate::create([
            'key' => 'test-demo-template',
            'name' => 'Test Demo Template',
            'template_path' => 'test-demo-folder',
            'is_active' => true
        ]);

        // 2. Create the dummy template folder on disk so replication doesn't fail
        $folderPath = $this->templateDir . '/test-demo-folder';
        File::makeDirectory($folderPath, 0755, true);
        File::put($folderPath . '/index.html', 'dummy index');

        // 3. Dispatch the job synchronously
        \App\Jobs\DeployDemoInstanceJob::dispatchSync($template);

        // 4. Verify deployment record created in DB
        $this->assertDatabaseHas('deployments', [
            'service_template_id' => $template->id,
            'client_slug' => 'demo.test-demo-template',
            'status' => \App\Enums\DeploymentStatus::ACTIVE->value
        ]);

        // Clean up the instance path if created
        $instancePath = config('deploy.instance_base_path') . '/demo.test-demo-template';
        if (File::isDirectory($instancePath)) {
            File::deleteDirectory($instancePath);
        }
    }
}
