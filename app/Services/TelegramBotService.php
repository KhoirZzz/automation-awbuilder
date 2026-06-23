<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramBotService
{
    protected string $token;

    public function __construct()
    {
        $this->token = config('services.telegram.bot_token', '');
    }

    /**
     * Send text message.
     */
    public function sendMessage(string|int $chatId, string $text, array $extra = []): bool
    {
        if (empty($this->token)) {
            Log::channel('deploy-audit')->warning('Telegram bot token not configured in .env. Message sending skipped.', [
                'chat_id' => $chatId,
                'text' => $text
            ]);
            return false;
        }

        try {
            $response = Http::post("https://api.telegram.org/bot{$this->token}/sendMessage", array_merge([
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML'
            ], $extra));

            if (!$response->successful()) {
                Log::channel('deploy-audit')->error('Telegram API error sending message.', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::channel('deploy-audit')->error('Failed to send Telegram message: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send photo (like QRIS QR code).
     */
    public function sendPhoto(string|int $chatId, string $photoUrl, string $caption): bool
    {
        if (empty($this->token)) {
            Log::channel('deploy-audit')->warning('Telegram bot token not configured in .env. Send photo skipped.');
            return false;
        }

        try {
            $response = Http::post("https://api.telegram.org/bot{$this->token}/sendPhoto", [
                'chat_id' => $chatId,
                'photo' => $photoUrl,
                'caption' => $caption,
                'parse_mode' => 'HTML'
            ]);

            if (!$response->successful()) {
                Log::channel('deploy-audit')->error('Telegram API error sending photo.', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                // Fallback to text message if photo fails
                return $this->sendMessage($chatId, $caption . "\n\n(QRIS URL: {$photoUrl})");
            }

            return true;
        } catch (\Exception $e) {
            Log::channel('deploy-audit')->error('Failed to send Telegram photo: ' . $e->getMessage());
            return $this->sendMessage($chatId, $caption . "\n\n(QRIS URL: {$photoUrl})");
        }
    }
}
