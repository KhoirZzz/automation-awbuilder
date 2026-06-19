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

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->uploadDir = storage_path('app/uploads');
        $this->templateDir = storage_path('testing/layanan');

        config([
            'deploy.template_base_path' => $this->templateDir,
        ]);

        if (File::isDirectory($this->uploadDir)) {
            File::deleteDirectory($this->uploadDir);
        }
        if (File::isDirectory($this->templateDir)) {
            File::deleteDirectory($this->templateDir);
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
        parent::tearDown();
    }

    public function test_upload_zip_endpoint(): void
    {
        // Mock a zip file
        $file = UploadedFile::fake()->create('test-template.zip', 100, 'application/zip');

        $response = $this->postJson('/api/dashboard/templates/upload-zip', [
            'zip_file' => $file,
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

        $response = $this->getJson('/api/dashboard/templates/zips');

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
        ]);

        $response->assertStatus(422); // Validation error
    }
}
