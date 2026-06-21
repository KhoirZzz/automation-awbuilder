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

        $response = $this->getJson('/api/dashboard/agent/config', [
            'X-Admin-Passkey' => '852963'
        ]);

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
        ], [
            'X-Admin-Passkey' => '852963'
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
        ], [
            'X-Admin-Passkey' => '852963'
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
        ], [
            'X-Admin-Passkey' => '852963'
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'error' => 'Akses ditolak. Passkey tidak valid.'
        ]);
    }

    public function test_persist_and_retrieve_agent_chat_history(): void
    {
        // 1. Post mock history
        $response = $this->postJson('/api/dashboard/agent/persist-history', [
            'chat_history' => [
                [
                    'role' => 'user',
                    'content' => 'Hello AI!',
                    'isError' => false,
                    'isDeploying' => false,
                    'url' => null,
                ],
                [
                    'role' => 'assistant',
                    'content' => 'Hi Developer!',
                    'isError' => false,
                    'isDeploying' => false,
                    'url' => 'http://test.mockbuild.shop',
                ]
            ],
            'passkey' => '852963'
        ], [
            'X-Admin-Passkey' => '852963'
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('agent_chats', [
            'role' => 'user',
            'content' => 'Hello AI!',
        ]);

        $this->assertDatabaseHas('agent_chats', [
            'role' => 'assistant',
            'content' => 'Hi Developer!',
            'url' => 'http://test.mockbuild.shop',
        ]);

        // 2. Retrieve history through getAgentConfig
        $responseGet = $this->getJson('/api/dashboard/agent/config', [
            'X-Admin-Passkey' => '852963'
        ]);

        $responseGet->assertStatus(200);
        $responseGet->assertJsonFragment([
            'chat_history' => [
                [
                    'role' => 'user',
                    'content' => 'Hello AI!',
                    'isError' => false,
                    'isDeploying' => false,
                    'url' => null,
                ],
                [
                    'role' => 'assistant',
                    'content' => 'Hi Developer!',
                    'isError' => false,
                    'isDeploying' => false,
                    'url' => 'http://test.mockbuild.shop',
                ]
            ]
        ]);
    }
}
