<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentPlaygroundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['deploy.agent_passkey' => '852963']);
    }

    public function test_get_agent_config_endpoint(): void
    {
        config([
            'deploy.template_base_path' => storage_path('templates'),
        ]);

        $response = $this->getJson('/api/dashboard/agent/config');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'model',
            'api_url',
            'has_api_key',
            'default_system_prompt'
        ]);
    }

    public function test_agent_chat_endpoint_success(): void
    {
        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Simulated agent response text.'
                        ]
                    ]
                ]
            ], 200)
        ]);

        $response = $this->postJson('/api/dashboard/agent/chat', [
            'system_prompt' => 'You are a helpful assistant.',
            'message' => 'Hello AI Worker!',
            'passkey' => '852963'
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'response' => 'Simulated agent response text.'
        ]);
    }

    public function test_agent_chat_endpoint_failure(): void
    {
        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response([], 500)
        ]);

        $response = $this->postJson('/api/dashboard/agent/chat', [
            'system_prompt' => 'You are a helpful assistant.',
            'message' => 'Hello AI Worker!',
            'passkey' => '852963'
        ]);

        $response->assertStatus(500);
        $response->assertJson([
            'success' => false
        ]);
    }

    public function test_agent_chat_endpoint_invalid_passkey(): void
    {
        $response = $this->postJson('/api/dashboard/agent/chat', [
            'system_prompt' => 'You are a helpful assistant.',
            'message' => 'Hello AI Worker!',
            'passkey' => 'wrongkey'
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'error' => 'Akses ditolak. Passkey tidak valid.'
        ]);
    }
}
