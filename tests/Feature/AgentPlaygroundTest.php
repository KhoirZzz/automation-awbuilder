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
        config([
            'deploy.agent_passkey' => '852963',
            'services.hermes.api_url' => 'https://integrate.api.nvidia.com/v1/chat/completions'
        ]);
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

    public function test_agent_chat_with_read_file_tool_call(): void
    {
        // Set up temporary mock folder structures
        $clientSlug = 'agoda-test';
        $instancePath = storage_path('app/deployments/' . $clientSlug);
        if (!\Illuminate\Support\Facades\File::isDirectory($instancePath)) {
            \Illuminate\Support\Facades\File::makeDirectory($instancePath, 0755, true);
        }
        $testFile = $instancePath . '/index.html';
        \Illuminate\Support\Facades\File::put($testFile, '<h1>Welcome to Agoda!</h1>');

        // Create deployment record
        $template = \App\Models\ServiceTemplate::create([
            'key' => 'agoda',
            'name' => 'Agoda App',
            'category' => 'landing',
            'template_path' => 'layanan/agoda',
            'is_active' => true,
        ]);

        \App\Models\Deployment::create([
            'source' => 'telegram',
            'lead_reference' => '1234',
            'service_template_id' => $template->id,
            'client_slug' => $clientSlug,
            'instance_path' => $instancePath,
            'started_at' => now(),
            'expires_at' => now()->addWeek(),
            'status' => \App\Enums\DeploymentStatus::ACTIVE,
            'price' => 100000,
        ]);

        // Fake LLM Responses:
        // 1st call asks to read the file
        // 2nd call responds with a friendly text after receiving file content
        Http::fake([
            'integrate.api.nvidia.com/*' => Http::sequence([
                Http::response([
                    'choices' => [
                        [
                            'message' => [
                                'content' => json_encode([
                                    'status' => 'read_file',
                                    'client_slug' => $clientSlug,
                                    'file_path' => 'index.html',
                                ])
                            ]
                        ]
                    ]
                ], 200),
                Http::response([
                    'choices' => [
                        [
                            'message' => [
                                'content' => 'Saya sudah membaca file tersebut, isinya adalah Welcome to Agoda!'
                            ]
                        ]
                    ]
                ], 200),
            ])
        ]);

        $response = $this->postJson('/api/dashboard/agent/chat', [
            'message' => 'Tolong baca isi file index.html di subdomain agoda-test.',
            'passkey' => '852963'
        ], [
            'X-Admin-Passkey' => '852963'
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'response' => 'Saya sudah membaca file tersebut, isinya adalah Welcome to Agoda!'
        ]);

        // Clean up
        \Illuminate\Support\Facades\File::deleteDirectory(storage_path('app/deployments'));
    }

    public function test_agent_chat_with_write_file_tool_call(): void
    {
        $clientSlug = 'agoda-test-write';
        $instancePath = storage_path('app/deployments/' . $clientSlug);
        if (!\Illuminate\Support\Facades\File::isDirectory($instancePath)) {
            \Illuminate\Support\Facades\File::makeDirectory($instancePath, 0755, true);
        }
        $testFile = $instancePath . '/index.html';
        \Illuminate\Support\Facades\File::put($testFile, '<h1>Welcome to Agoda!</h1>');

        $template = \App\Models\ServiceTemplate::create([
            'key' => 'agoda',
            'name' => 'Agoda App',
            'category' => 'landing',
            'template_path' => 'layanan/agoda',
            'is_active' => true,
        ]);

        \App\Models\Deployment::create([
            'source' => 'telegram',
            'lead_reference' => '1234',
            'service_template_id' => $template->id,
            'client_slug' => $clientSlug,
            'instance_path' => $instancePath,
            'started_at' => now(),
            'expires_at' => now()->addWeek(),
            'status' => \App\Enums\DeploymentStatus::ACTIVE,
            'price' => 100000,
        ]);

        // Fake LLM responses:
        // 1st call asks to write to the file
        // 2nd call responds with a friendly response after file modification
        Http::fake([
            'integrate.api.nvidia.com/*' => Http::sequence([
                Http::response([
                    'choices' => [
                        [
                            'message' => [
                                'content' => json_encode([
                                    'status' => 'write_file',
                                    'client_slug' => $clientSlug,
                                    'file_path' => 'index.html',
                                    'content' => '<h2>Hello World</h2>',
                                    'target' => '<h1>Welcome to Agoda!</h1>'
                                ])
                            ]
                        ]
                    ]
                ], 200),
                Http::response([
                    'choices' => [
                        [
                            'message' => [
                                'content' => 'Saya sudah mengganti judul di file index.html menjadi Hello World.'
                            ]
                        ]
                    ]
                ], 200),
            ])
        ]);

        $response = $this->postJson('/api/dashboard/agent/chat', [
            'message' => 'Tolong ubah H1 di index.html di subdomain agoda-test-write.',
            'passkey' => '852963'
        ], [
            'X-Admin-Passkey' => '852963'
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'response' => 'Saya sudah mengganti judul di file index.html menjadi Hello World.'
        ]);

        // Verify file modified
        $this->assertEquals('<h2>Hello World</h2>', \Illuminate\Support\Facades\File::get($testFile));

        // Clean up
        \Illuminate\Support\Facades\File::deleteDirectory(storage_path('app/deployments'));
    }

    public function test_agent_chat_rejects_directory_traversal(): void
    {
        $clientSlug = 'agoda-test-traversal';
        $instancePath = storage_path('app/deployments/' . $clientSlug);
        if (!\Illuminate\Support\Facades\File::isDirectory($instancePath)) {
            \Illuminate\Support\Facades\File::makeDirectory($instancePath, 0755, true);
        }

        $template = \App\Models\ServiceTemplate::create([
            'key' => 'agoda',
            'name' => 'Agoda App',
            'category' => 'landing',
            'template_path' => 'layanan/agoda',
            'is_active' => true,
        ]);

        \App\Models\Deployment::create([
            'source' => 'telegram',
            'lead_reference' => '1234',
            'service_template_id' => $template->id,
            'client_slug' => $clientSlug,
            'instance_path' => $instancePath,
            'started_at' => now(),
            'expires_at' => now()->addWeek(),
            'status' => \App\Enums\DeploymentStatus::ACTIVE,
            'price' => 100000,
        ]);

        // 1st response: attempt path traversal
        // 2nd response: LLM gets the error, yields conversation response
        Http::fake([
            'integrate.api.nvidia.com/*' => Http::sequence([
                Http::response([
                    'choices' => [
                        [
                            'message' => [
                                'content' => json_encode([
                                    'status' => 'read_file',
                                    'client_slug' => $clientSlug,
                                    'file_path' => '../../../../etc/passwd',
                                ])
                            ]
                        ]
                    ]
                ], 200),
                Http::response([
                    'choices' => [
                        [
                            'message' => [
                                'content' => 'Maaf, saya tidak diizinkan mengakses file di luar sandbox.'
                            ]
                        ]
                    ]
                ], 200),
            ])
        ]);

        $response = $this->postJson('/api/dashboard/agent/chat', [
            'message' => 'Tolong baca file rahasia.',
            'passkey' => '852963'
        ], [
            'X-Admin-Passkey' => '852963'
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'response' => 'Maaf, saya tidak diizinkan mengakses file di luar sandbox.'
        ]);

        // Clean up
        \Illuminate\Support\Facades\File::deleteDirectory(storage_path('app/deployments'));
    }

    public function test_agent_chat_parses_conversational_json_tool_calls(): void
    {
        $clientSlug = 'agoda-test-conversational';
        $instancePath = storage_path('app/deployments/' . $clientSlug);
        if (!\Illuminate\Support\Facades\File::isDirectory($instancePath)) {
            \Illuminate\Support\Facades\File::makeDirectory($instancePath, 0755, true);
        }

        $testFile = $instancePath . '/index.php';
        \Illuminate\Support\Facades\File::put($testFile, '<?php echo "Old Content";');

        $template = \App\Models\ServiceTemplate::create([
            'key' => 'agoda',
            'name' => 'Agoda App',
            'category' => 'landing',
            'template_path' => 'layanan/agoda',
            'is_active' => true,
        ]);

        \App\Models\Deployment::create([
            'source' => 'telegram',
            'lead_reference' => '12345',
            'service_template_id' => $template->id,
            'client_slug' => $clientSlug,
            'instance_path' => $instancePath,
            'started_at' => now(),
            'expires_at' => now()->addWeek(),
            'status' => \App\Enums\DeploymentStatus::ACTIVE,
            'price' => 100000,
        ]);

        // Fake LLM response with conversational intro and markdown code blocks
        Http::fake([
            'integrate.api.nvidia.com/*' => Http::sequence([
                Http::response([
                    'choices' => [
                        [
                            'message' => [
                                'content' => "Tentu Tuan Ridzz, saya akan mengedit file tersebut.\n" .
                                    "```json\n" .
                                    "{\n" .
                                    "  \"status\": \"write_file\",\n" .
                                    "  \"client_slug\": \"{$clientSlug}\",\n" .
                                    "  \"file_path\": \"index.php\",\n" .
                                    "  \"content\": \"<?php echo 'New Content';\"\n" .
                                    "}\n" .
                                    "```\n" .
                                    "Semoga berhasil!"
                            ]
                        ]
                    ]
                ], 200),
                Http::response([
                    'choices' => [
                        [
                            'message' => [
                                'content' => 'Saya sudah memperbarui file index.php sesuai permintaan Anda, Tuan Ridzz.'
                            ]
                        ]
                    ]
                ], 200),
            ])
        ]);

        $response = $this->postJson('/api/dashboard/agent/chat', [
            'message' => 'Edit file index.php di agoda-test-conversational.',
            'passkey' => '852963'
        ], [
            'X-Admin-Passkey' => '852963'
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'response' => 'Saya sudah memperbarui file index.php sesuai permintaan Anda, Tuan Ridzz.'
        ]);

        // Verify file modified
        $this->assertEquals("<?php echo 'New Content';", \Illuminate\Support\Facades\File::get($testFile));

        // Clean up
        \Illuminate\Support\Facades\File::deleteDirectory(storage_path('app/deployments'));
    }

    public function test_agent_chat_with_read_file_tool_call_for_pending_payment_deployment(): void
    {
        $clientSlug = 'agoda-test-pending';
        $instancePath = storage_path('app/deployments/' . $clientSlug);
        if (!\Illuminate\Support\Facades\File::isDirectory($instancePath)) {
            \Illuminate\Support\Facades\File::makeDirectory($instancePath, 0755, true);
        }
        $testFile = $instancePath . '/index.html';
        \Illuminate\Support\Facades\File::put($testFile, '<h1>Welcome to Agoda Pending!</h1>');

        $template = \App\Models\ServiceTemplate::create([
            'key' => 'agoda',
            'name' => 'Agoda App',
            'category' => 'landing',
            'template_path' => 'layanan/agoda',
            'is_active' => true,
        ]);

        \App\Models\Deployment::create([
            'source' => 'telegram',
            'lead_reference' => '1234-pending',
            'service_template_id' => $template->id,
            'client_slug' => $clientSlug,
            'instance_path' => $instancePath,
            'started_at' => now(),
            'expires_at' => now()->addWeek(),
            'status' => \App\Enums\DeploymentStatus::PENDING_PAYMENT,
            'price' => 100000,
        ]);

        Http::fake([
            'integrate.api.nvidia.com/*' => Http::sequence([
                Http::response([
                    'choices' => [
                        [
                            'message' => [
                                'content' => json_encode([
                                    'status' => 'read_file',
                                    'client_slug' => $clientSlug,
                                    'file_path' => 'index.html',
                                ])
                            ]
                        ]
                    ]
                ], 200),
                Http::response([
                    'choices' => [
                        [
                            'message' => [
                                'content' => 'Saya sudah membaca file tersebut, isinya adalah Welcome to Agoda Pending!'
                            ]
                        ]
                    ]
                ], 200),
            ])
        ]);

        $response = $this->postJson('/api/dashboard/agent/chat', [
            'message' => 'Tolong baca isi file index.html di subdomain agoda-test-pending.',
            'passkey' => '852963'
        ], [
            'X-Admin-Passkey' => '852963'
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'response' => 'Saya sudah membaca file tersebut, isinya adalah Welcome to Agoda Pending!'
        ]);

        \Illuminate\Support\Facades\File::deleteDirectory(storage_path('app/deployments'));
    }
}
