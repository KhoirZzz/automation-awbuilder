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

    /**
     * Send a document file (e.g. PDF invoice) via local path.
     *
     * @param string|int $chatId
     * @param string     $filePath Absolute local path to the file
     * @param string     $caption  Caption for the document
     * @param string|null $token   Override bot token (e.g. buyer's bot). Uses admin bot if null.
     */
    public function sendDocument(string|int $chatId, string $filePath, string $caption = '', ?string $token = null): bool
    {
        $useToken = $token ?? $this->token;

        if (empty($useToken)) {
            Log::channel('deploy-audit')->warning('Telegram token not configured. sendDocument skipped.', [
                'chat_id' => $chatId,
            ]);
            return false;
        }

        if (!file_exists($filePath)) {
            Log::channel('deploy-audit')->error('sendDocument: file does not exist', ['path' => $filePath]);
            return false;
        }

        try {
            $response = Http::withToken($useToken)
                ->attach('document', file_get_contents($filePath), basename($filePath))
                ->post("https://api.telegram.org/bot{$useToken}/sendDocument", [
                    'chat_id'    => $chatId,
                    'caption'    => $caption,
                    'parse_mode' => 'HTML',
                ]);

            if (!$response->successful()) {
                Log::channel('deploy-audit')->error('Telegram API error sending document.', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::channel('deploy-audit')->error('Failed to send Telegram document: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Download a file from Telegram by its file_id.
     * Returns ['content' => binary_string, 'mime' => 'image/jpeg'] or null on failure.
     *
     * @param string      $fileId   Telegram file_id
     * @param string|null $token    Override bot token. Uses admin bot if null.
     * @return array{content: string, mime: string}|null
     */
    public function downloadFile(string $fileId, ?string $token = null): ?array
    {
        $useToken = $token ?? $this->token;

        if (empty($useToken)) {
            Log::channel('deploy-audit')->warning('Telegram token not configured. downloadFile skipped.');
            return null;
        }

        try {
            // Step 1: Get file path from Telegram
            $fileResponse = Http::get("https://api.telegram.org/bot{$useToken}/getFile", [
                'file_id' => $fileId,
            ]);

            if (!$fileResponse->successful()) {
                Log::channel('deploy-audit')->error('[TelegramBotService] getFile failed', [
                    'status' => $fileResponse->status(),
                    'body'   => $fileResponse->body(),
                ]);
                return null;
            }

            $filePath = $fileResponse->json('result.file_path');
            if (!$filePath) {
                Log::channel('deploy-audit')->error('[TelegramBotService] No file_path in getFile response');
                return null;
            }

            // Step 2: Download the actual file
            $downloadUrl  = "https://api.telegram.org/file/bot{$useToken}/{$filePath}";
            $fileContents = Http::timeout(30)->get($downloadUrl);

            if (!$fileContents->successful()) {
                Log::channel('deploy-audit')->error('[TelegramBotService] File download failed', [
                    'url'    => $downloadUrl,
                    'status' => $fileContents->status(),
                ]);
                return null;
            }

            // Determine MIME type from file extension
            $ext  = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png'         => 'image/png',
                'gif'         => 'image/gif',
                'webp'        => 'image/webp',
                default       => 'image/jpeg',
            };

            return [
                'content' => $fileContents->body(),
                'mime'    => $mime,
            ];
        } catch (\Exception $e) {
            Log::channel('deploy-audit')->error('[TelegramBotService] Exception in downloadFile: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Send photo with inline keyboard markup.
     */
    public function sendPhotoWithKeyboard(string|int $chatId, string $photoFileId, string $caption, array $keyboard = []): bool
    {
        if (empty($this->token)) {
            Log::channel('deploy-audit')->warning('Telegram bot token not configured. sendPhotoWithKeyboard skipped.');
            return false;
        }

        try {
            $params = [
                'chat_id' => $chatId,
                'photo' => $photoFileId,
                'caption' => $caption,
                'parse_mode' => 'HTML',
            ];

            if (!empty($keyboard)) {
                $params['reply_markup'] = json_encode([
                    'inline_keyboard' => $keyboard
                ]);
            }

            $response = Http::post("https://api.telegram.org/bot{$this->token}/sendPhoto", $params);

            if (!$response->successful()) {
                Log::channel('deploy-audit')->error('Telegram API error in sendPhotoWithKeyboard.', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::channel('deploy-audit')->error('Failed to send photo with keyboard: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Answer Telegram callback query.
     */
    public function answerCallbackQuery(string $callbackQueryId, string $text, bool $showAlert = false): bool
    {
        if (empty($this->token)) {
            return false;
        }

        try {
            $response = Http::post("https://api.telegram.org/bot{$this->token}/answerCallbackQuery", [
                'callback_query_id' => $callbackQueryId,
                'text' => $text,
                'show_alert' => $showAlert,
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::channel('deploy-audit')->error('Failed to answer callback query: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Edit caption of an existing message.
     */
    public function editMessageCaption(string|int $chatId, int $messageId, string $caption, array $keyboard = []): bool
    {
        if (empty($this->token)) {
            return false;
        }

        try {
            $params = [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'caption' => $caption,
                'parse_mode' => 'HTML',
            ];

            $params['reply_markup'] = json_encode([
                'inline_keyboard' => $keyboard
            ]);

            $response = Http::post("https://api.telegram.org/bot{$this->token}/editMessageCaption", $params);

            if (!$response->successful()) {
                Log::channel('deploy-audit')->error('Telegram API error in editMessageCaption.', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::channel('deploy-audit')->error('Failed to edit message caption: ' . $e->getMessage());
            return false;
        }
    }
}
