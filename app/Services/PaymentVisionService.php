<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PaymentVisionService
 *
 * Uses the LLM (via NVIDIA NIM / OpenRouter) with vision capability to extract
 * the transfer/payment amount from a payment proof image sent to the admin Telegram bot.
 *
 * The model receives the image encoded as base64 and returns a structured JSON
 * with the detected nominal so we can match it against the expected price.
 */
class PaymentVisionService
{
    private string $apiUrl;
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        // Use a vision-capable model. Falls back to configured Hermes model.
        // NVIDIA NIM supports vision with e.g. "google/gemma-3-27b-it" or "meta/llama-4-scout-17b-16e-instruct"
        $this->apiUrl = config('services.hermes.url', env('HERMES_API_URL'));
        $this->apiKey = config('services.hermes.key', env('HERMES_API_KEY'));
        // Vision model — can be overridden via VISION_MODEL env
        $this->model  = env('VISION_MODEL', config('services.hermes.model', env('HERMES_MODEL', 'meta/llama-4-scout-17b-16e-instruct')));
    }

    /**
     * Analyze a payment proof image and extract the transfer nominal.
     *
     * @param string $imageBase64 Base64-encoded JPEG/PNG image
     * @param string $mimeType    e.g. "image/jpeg"
     * @return array{
     *   success: bool,
     *   nominal: int|null,
     *   confidence: string,
     *   raw_response: string
     * }
     */
    public function extractNominalFromImage(string $imageBase64, string $mimeType = 'image/jpeg'): array
    {
        $systemPrompt = <<<'PROMPT'
You are a payment verification assistant. Your ONLY task is to read a payment proof image (bank transfer, QRIS, e-wallet screenshot) and extract the transfer amount.

RULES:
1. Look for the total amount transferred/paid. Common labels: "Total", "Nominal", "Jumlah", "Amount", "Transfer Amount".
2. Return ONLY valid JSON with no extra text.
3. If you cannot determine the amount with confidence, set nominal to null and confidence to "low".
4. Convert the amount to a plain integer in IDR (Indonesian Rupiah). E.g. "Rp 100.000" → 100000.

Response format (strict JSON):
{"nominal": 100000, "confidence": "high", "currency": "IDR", "note": "Detected from QRIS payment screenshot"}
PROMPT;

        $headers = [
            'Accept'        => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
        ];

        $payload = [
            'model'       => $this->model,
            'messages'    => [
                ['role' => 'system', 'content' => $systemPrompt],
                [
                    'role'    => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Please analyze this payment proof image and extract the transfer nominal.',
                        ],
                        [
                            'type'      => 'image_url',
                            'image_url' => [
                                'url' => "data:{$mimeType};base64,{$imageBase64}",
                            ],
                        ],
                    ],
                ],
            ],
            'response_format' => ['type' => 'json_object'],
            'temperature'     => 0.0,
            'max_tokens'      => 200,
            'stream'          => false,
        ];

        try {
            $response = Http::withHeaders($headers)
                ->timeout(45)
                ->retry(2, 500)
                ->post($this->apiUrl, $payload);

            if (!$response->successful()) {
                Log::channel('deploy-audit')->error('[PaymentVision] LLM API error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                return ['success' => false, 'nominal' => null, 'confidence' => 'error', 'raw_response' => $response->body()];
            }

            $responseData = $response->json();
            $rawText      = $responseData['choices'][0]['message']['content'] ?? '{}';

            $parsed = json_decode($rawText, true);

            if (json_last_error() !== JSON_ERROR_NONE || !isset($parsed['nominal'])) {
                Log::channel('deploy-audit')->warning('[PaymentVision] Could not parse LLM response as JSON', [
                    'raw_text' => $rawText,
                ]);

                return ['success' => false, 'nominal' => null, 'confidence' => 'parse_error', 'raw_response' => $rawText];
            }

            $nominal = $parsed['nominal'] !== null ? (int) $parsed['nominal'] : null;

            Log::channel('deploy-audit')->info('[PaymentVision] Nominal extracted successfully', [
                'nominal'    => $nominal,
                'confidence' => $parsed['confidence'] ?? 'unknown',
            ]);

            return [
                'success'      => true,
                'nominal'      => $nominal,
                'confidence'   => $parsed['confidence'] ?? 'unknown',
                'raw_response' => $rawText,
            ];
        } catch (\Exception $e) {
            Log::channel('deploy-audit')->error('[PaymentVision] Exception during LLM call', [
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'nominal' => null, 'confidence' => 'exception', 'raw_response' => $e->getMessage()];
        }
    }
}
