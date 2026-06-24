<?php

namespace Tests\Feature;

use App\Jobs\ProcessLeadJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

use Illuminate\Foundation\Testing\RefreshDatabase;

class LeadWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Cache::flush();
    }

    public function test_telegram_webhook_without_signature_is_unauthorized(): void
    {
        $response = $this->postJson('/api/webhook/telegram', [
            'message' => [
                'message_id' => 123,
                'chat' => ['id' => 456],
                'text' => 'Deploy bot'
            ]
        ]);

        $response->assertStatus(401);
        Queue::assertNothingPushed();
    }

    public function test_telegram_webhook_with_valid_signature_dispatches_job(): void
    {
        $secretToken = 'my-tg-secret-token';
        config([
            'app.env' => 'production',
            'services.telegram.bot_secret_token' => $secretToken
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => $secretToken
        ])->postJson('/api/webhook/telegram', [
            'message' => [
                'message_id' => 123,
                'chat' => ['id' => 456],
                'text' => 'Deploy bot'
            ]
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'received']);

        Queue::assertPushed(ProcessLeadJob::class, function (ProcessLeadJob $job) {
            return $job->messageText === 'Deploy bot'
                && $job->source === 'telegram'
                && $job->leadReference === 'tg_456_123';
        });
    }

    public function test_telegram_webhook_deduplication(): void
    {
        $secretToken = 'my-tg-secret-token';
        config(['services.telegram.bot_secret_token' => $secretToken]);

        $payload = [
            'message' => [
                'message_id' => 123,
                'chat' => ['id' => 456],
                'text' => 'Deploy bot'
            ]
        ];

        // First call
        $response1 = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => $secretToken
        ])->postJson('/api/webhook/telegram', $payload);

        $response1->assertStatus(200);

        // Second call (duplicate within TTL)
        $response2 = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => $secretToken
        ])->postJson('/api/webhook/telegram', $payload);

        $response2->assertStatus(200);
        $response2->assertJson(['duplicate' => true]);

        // Assert job only dispatched once
        Queue::assertPushed(ProcessLeadJob::class, 1);
    }

    public function test_whatsapp_webhook_without_signature_is_unauthorized(): void
    {
        $response = $this->postJson('/api/webhook/whatsapp', [
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'messages' => [
                                    [
                                        'id' => 'wamid.123',
                                        'text' => ['body' => 'Deploy bot']
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        $response->assertStatus(401);
        Queue::assertNothingPushed();
    }

    public function test_whatsapp_webhook_with_valid_signature_dispatches_job(): void
    {
        $appSecret = 'my-wa-app-secret';
        config(['services.whatsapp.app_secret' => $appSecret]);

        $payload = [
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'messages' => [
                                    [
                                        'id' => 'wamid123',
                                        'text' => ['body' => 'Deploy bot']
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $rawBody = json_encode($payload);
        $signature = 'sha256=' . hash_hmac('sha256', $rawBody, $appSecret);

        $response = $this->withHeaders([
            'X-Hub-Signature-256' => $signature
        ])->postJson('/api/webhook/whatsapp', $payload);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'received']);

        Queue::assertPushed(ProcessLeadJob::class, function (ProcessLeadJob $job) {
            return $job->messageText === 'Deploy bot'
                && $job->source === 'whatsapp'
                && $job->leadReference === 'wa_wamid123';
        });
    }

    public function test_whatsapp_webhook_verification_challenge_passes(): void
    {
        $verifyToken = 'my-verify-token';
        config(['services.whatsapp.verify_token' => $verifyToken]);

        $response = $this->get('/api/webhook/whatsapp?' . http_build_query([
            'hub_mode' => 'subscribe',
            'hub_challenge' => '1158201444',
            'hub_verify_token' => $verifyToken
        ]));

        $response->assertStatus(200);
        $this->assertEquals('1158201444', $response->getContent());
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    }
}
