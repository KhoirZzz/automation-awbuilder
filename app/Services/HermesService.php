<?php

namespace App\Services;

use App\Exceptions\HermesResponseException;
use App\Enums\ServiceDuration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HermesService
{
    /**
     * Analyze a lead message using the Hermes model.
     *
     * @param string $message
     * @param Collection $activeTemplates Collection of ServiceTemplate models
     * @return array
     * @throws HermesResponseException
     */
    public function analyzeLead(string $message, Collection $activeTemplates): array
    {
        $apiUrl = env('HERMES_API_URL', 'http://localhost:11434/v1/chat/completions');
        $apiKey = env('HERMES_API_KEY');
        $model = env('HERMES_MODEL', 'hermes3');

        $activeServiceKeys = $activeTemplates->pluck('key')->toArray();
        $durationKeys = collect(ServiceDuration::cases())->map(fn ($case) => $case->value)->toArray();

        $systemPrompt = $this->buildSystemPrompt($activeServiceKeys, $durationKeys);

        $headers = [
            'Accept' => 'application/json',
        ];
        if ($apiKey) {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout(30)
                ->retry(2, 200)
                ->post($apiUrl, [
                    'model' => $model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $systemPrompt
                        ],
                        [
                            'role' => 'user',
                            'content' => $message
                        ]
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0.0,
                    'stream' => false
                ]);
        } catch (\Exception $e) {
            Log::channel('deploy-audit')->error('HTTP connection to Hermes failed.', [
                'error' => $e->getMessage()
            ]);
            throw new HermesResponseException('HTTP connection to Hermes failed: ' . $e->getMessage(), 0, $e);
        }

        if (!$response->successful()) {
            Log::channel('deploy-audit')->error('Hermes API returned non-successful code.', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            throw new HermesResponseException('Hermes API returned non-successful response: ' . $response->status());
        }

        $responseData = $response->json();
        $responseText = $responseData['choices'][0]['message']['content'] ?? null;

        if (empty($responseText)) {
            Log::channel('deploy-audit')->error('Hermes API returned empty response content.', [
                'response' => $responseData
            ]);
            throw new HermesResponseException('Hermes API returned empty response content.');
        }

        // Clean up response if it has markdown fences
        $cleanedResponse = $this->cleanResponseText($responseText);

        $decoded = json_decode($cleanedResponse, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::channel('deploy-audit')->error('Failed to parse Hermes response as JSON.', [
                'raw_text' => $responseText,
                'cleaned_text' => $cleanedResponse,
                'json_error' => json_last_error_msg()
            ]);
            throw new HermesResponseException('Failed to parse Hermes response as JSON: ' . json_last_error_msg());
        }

        return $decoded;
    }

    /**
     * Build dynamic system prompt.
     */
    private function buildSystemPrompt(array $serviceKeys, array $durations): string
    {
        $servicesList = implode(', ', array_map(fn ($k) => '"' . $k . '"', $serviceKeys));
        $durationsList = implode(', ', array_map(fn ($d) => '"' . $d . '"', $durations));

        return <<<PROMPT
You are a precise data extractor for an automatic deployment system.
Your job is to analyze the user's order message and extract the following fields in strict JSON format.

Available Services (service_key): [{$servicesList}]
Available Durations (durasi): [{$durationsList}]

You must respond ONLY with a raw JSON object containing these keys:
1. "service_key": (string) Must match EXACTLY one of the available services listed above. If no match is found, return null.
2. "durasi": (string) Must match EXACTLY one of the available durations listed above. If no match is found, return null.
3. "client_slug_request": (string) A suggested subdomain or directory name for this client's instance, derived from the client's name or business.
4. "telegram_token": (string|null) Extract any Telegram Bot Token present in the user request (format: digits:alphanumeric). If not present, return null.
5. "telegram_chat_id": (string|null) Extract any Telegram Chat ID or Group ID present in the user request. If not present, return null.
6. "price": (integer|string|null) Extract the price mentioned in the request (e.g. 100k -> 100000, 1.5jt -> 1500000, 150000 -> 150000). If not present, return null.

Do not include any chat formatting, markdown block styling (like ```json), commentary, or extra whitespace.

Example Output:
{
  "service_key": "shopee-bot",
  "durasi": "1_minggu",
  "client_slug_request": "tokoabc",
  "telegram_token": "8820475519:AAEGwriFkJA1k8Q2faZw_gRKNiUBxJJw4tY",
  "telegram_chat_id": "8752387283",
  "price": 100000
}
PROMPT;
    }

    /**
     * Build system prompt for playground chat.
     */
    public function buildAgentPlaygroundSystemPrompt(array $serviceKeys, array $durations): string
    {
        $servicesList = implode(', ', array_map(fn ($k) => '"' . $k . '"', $serviceKeys));
        $durationsList = implode(', ', array_map(fn ($d) => '"' . $d . '"', $durations));

        return <<<PROMPT
You are the AI Worker of the Auto-Deployment System.
You are a helpful assistant that can both process client deployment orders and answer questions about the auto-deployment system as a whole.

=== SYSTEM ARCHITECTURE & RULES ===
1. Webhooks & Security:
   - Telegram Webhook: POST `/api/webhook/telegram` validated via `X-Telegram-Bot-Api-Secret-Token` header signature.
   - WhatsApp Webhook: GET/POST `/api/webhook/whatsapp` validated via Hub Challenge and SHA256 HMAC signature.
   - Deduplication: Cached lock for 5 minutes prevents duplicate webhooks.
2. Parameter Extraction & Validation:
   - Available templates: [{$servicesList}]
   - Available durations: [{$durationsList}]
   - Client slug validation: Lowercase, alphanumeric & hyphens only, starts/ends with alphanumeric, max 63 characters (RFC 1035). Spacers, path traversals (..), and reserved subdomains (e.g. admin, api, www, auth) are rejected.
3. Directory Replication & Script Execution:
   - Copies templates from `storage/templates` to `storage/deployments`.
   - Injects `.env` config (CLIENT_SLUG, DEPLOY_EXPIRES_AT, DEPLOY_STARTED_AT).
   - Runs `deploy.sh` with a 60-second timeout.
   - Transactional rollback: Automatically deletes the client folder and marks database status as `failed` if `deploy.sh` fails.
4. Lifecycle Audit (Teardown):
   - Artisan command `deploy:audit-expired` runs daily.
   - Runs `teardown.sh` for expired instances, moves the directory to `storage/deployments_archive` (timestamped), and updates status to `expired`.

=== BEHAVIOR RULES ===
- Greet your creator as 'Tuan Ridzz' or 'Ridzz'.
- When the user asks to deploy a website/service, check if ALL the following required parameters are present:
  1. `service_key` (must match one of active templates: [{$servicesList}])
  2. `durasi` (must match one of [{$durationsList}])
  3. `client_slug_request` (a suggested subdomain slug)
  4. `telegram_token` (bot api token, e.g. 8698960345:AAEh...)
  5. `telegram_chat_id` (user chat id, e.g. 7860981010)

- If ANY of these parameters are missing, do NOT output a JSON. Instead, reply in polite Indonesian, greet the master, and ask for the missing parameters (e.g., "Tuan Ridzz, saya memerlukan Telegram Token dan Chat ID klien untuk melanjutkan...").
- If ALL of these parameters are complete, you MUST output ONLY a raw JSON payload in this format (no markdown blocks, no conversational text before or after, just the raw JSON object):
{
  "status": "ready_to_deploy",
  "service_key": "service-key",
  "durasi": "duration-value",
  "client_slug_request": "slug-value",
  "telegram_token": "token-value",
  "telegram_chat_id": "chat-id-value",
  "price": 100000
}
- If the user chats about general topics, system structure, or workflows, answer in a friendly, helpful, and professional manner, explaining how the components work together.
PROMPT;
    }

    /**
     * Remove markdown fences from response if present.
     */
    private function cleanResponseText(string $text): string
    {
        $text = trim($text);
        if (str_starts_with($text, '```json')) {
            $text = substr($text, 7);
        } elseif (str_starts_with($text, '```')) {
            $text = substr($text, 3);
        }
        if (str_ends_with($text, '```')) {
            $text = substr($text, 0, -3);
        }
        return trim($text);
    }

    /**
     * Send a custom chat prompt to the LLM agent.
     *
     * @param string $systemPrompt
     * @param array|string $messages
     * @return string
     * @throws HermesResponseException
     */
    public function chat(string $systemPrompt, array|string $messages): string
    {
        $apiUrl = env('HERMES_API_URL', 'http://localhost:11434/v1/chat/completions');
        $apiKey = env('HERMES_API_KEY');
        $model = env('HERMES_MODEL', 'hermes3');

        $headers = [
            'Accept' => 'application/json',
        ];
        if ($apiKey) {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        $payloadMessages = [
            [
                'role' => 'system',
                'content' => $systemPrompt
            ]
        ];

        if (is_array($messages)) {
            foreach ($messages as $msg) {
                $payloadMessages[] = [
                    'role' => $msg['role'] ?? 'user',
                    'content' => $msg['content'] ?? ''
                ];
            }
        } else {
            $payloadMessages[] = [
                'role' => 'user',
                'content' => $messages
            ];
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout(30)
                ->post($apiUrl, [
                    'model' => $model,
                    'messages' => $payloadMessages,
                    'temperature' => 0.7,
                    'stream' => false
                ]);
        } catch (\Exception $e) {
            throw new \App\Exceptions\HermesResponseException('HTTP connection to LLM failed: ' . $e->getMessage(), 0, $e);
        }

        if (!$response->successful()) {
            throw new \App\Exceptions\HermesResponseException('LLM API returned non-successful response: ' . $response->status());
        }

        $responseData = $response->json();
        $responseText = $responseData['choices'][0]['message']['content'] ?? null;

        if (empty($responseText)) {
            throw new \App\Exceptions\HermesResponseException('LLM API returned empty response content.');
        }

        return $responseText;
    }
}
