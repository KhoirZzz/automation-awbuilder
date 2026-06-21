<?php

namespace Tests\Feature;

use App\Models\ServiceTemplate;
use App\Models\Deployment;
use App\Enums\DeploymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardDeploymentTest extends TestCase
{
    use RefreshDatabase;

    private ServiceTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        config(['deploy.agent_passkey' => '852963']);

        $this->template = ServiceTemplate::create([
            'key' => 'gojek',
            'name' => 'Gojek Template',
            'template_path' => 'gojek',
            'is_active' => true,
        ]);
    }

    public function test_delete_deployment_requires_passkey(): void
    {
        $deployment = Deployment::create([
            'source' => 'telegram',
            'lead_reference' => 'tg_test',
            'service_template_id' => $this->template->id,
            'client_slug' => 'testslug',
            'status' => DeploymentStatus::EXPIRED,
            'instance_path' => storage_path('deployments/testslug'),
            'started_at' => now(),
            'expires_at' => now()->subDay(),
        ]);

        $response = $this->deleteJson("/api/dashboard/deployments/{$deployment->id}", [], [
            'X-Admin-Passkey' => 'wrong-passkey'
        ]);

        $response->assertStatus(401);
        $this->assertDatabaseHas('deployments', ['id' => $deployment->id]);
    }

    public function test_cannot_delete_active_deployment(): void
    {
        $deployment = Deployment::create([
            'source' => 'telegram',
            'lead_reference' => 'tg_test',
            'service_template_id' => $this->template->id,
            'client_slug' => 'testslug',
            'status' => DeploymentStatus::ACTIVE,
            'instance_path' => storage_path('deployments/testslug'),
            'started_at' => now(),
            'expires_at' => now()->addWeek(),
        ]);

        $response = $this->deleteJson("/api/dashboard/deployments/{$deployment->id}", [], [
            'X-Admin-Passkey' => '852963'
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'error' => 'Hanya deployment yang berstatus EXPIRED, FAILED, atau PENDING_PAYMENT yang dapat dihapus.'
        ]);
        $this->assertDatabaseHas('deployments', ['id' => $deployment->id]);
    }

    public function test_can_delete_expired_deployment(): void
    {
        $deployment = Deployment::create([
            'source' => 'telegram',
            'lead_reference' => 'tg_test',
            'service_template_id' => $this->template->id,
            'client_slug' => 'testslug',
            'status' => DeploymentStatus::EXPIRED,
            'instance_path' => storage_path('deployments/testslug'),
            'started_at' => now(),
            'expires_at' => now()->subDay(),
        ]);

        $response = $this->deleteJson("/api/dashboard/deployments/{$deployment->id}", [], [
            'X-Admin-Passkey' => '852963'
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Riwayat deployment berhasil dihapus.'
        ]);
        $this->assertDatabaseMissing('deployments', ['id' => $deployment->id]);
    }
}
