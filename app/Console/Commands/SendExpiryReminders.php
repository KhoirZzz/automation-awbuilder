<?php

namespace App\Console\Commands;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Services\TelegramBotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendExpiryReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy:send-expiry-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send automated expiry reminders to clients 3 days and 1 day before expiration';

    /**
     * Execute the console command.
     */
    public function handle(TelegramBotService $botService): int
    {
        $this->info('Starting checks for expiry reminders...');
        Log::channel('deploy-audit')->info('Scheduled checks for expiry reminders started.');

        $now = now();
        $threeDaysAhead = now()->addDays(3);
        $oneDayAhead = now()->addDays(1);

        // 1. Check for 3 days reminder
        $reminder3Days = Deployment::where('status', DeploymentStatus::ACTIVE)
            ->where('reminder_3_days_sent', false)
            ->where('expires_at', '<=', $threeDaysAhead)
            ->get();

        foreach ($reminder3Days as $deployment) {
            $chatId = $this->getClientChatId($deployment);
            if ($chatId) {
                $expiryFormatted = $deployment->expires_at->format('d-m-Y H:i');
                $message = "🔔 <b>PENGINGAT KEDALUWARSA</b>\n\nHalo! Layanan deployment Anda untuk subdomain <b>{$deployment->client_slug}</b> akan berakhir dalam <b>3 hari</b> (pada <b>{$expiryFormatted}</b>).\n\nSilakan hubungi Admin (<b>@awbuilderadmin</b>) untuk melakukan perpanjangan sebelum layanan terhenti secara otomatis.";
                
                $this->info("Sending 3 days reminder to client chat ID {$chatId} for {$deployment->client_slug}...");
                if ($botService->sendMessage($chatId, $message)) {
                    $deployment->update(['reminder_3_days_sent' => true]);
                    Log::channel('deploy-audit')->info('Sent 3 days expiry reminder.', [
                        'deployment_id' => $deployment->id,
                        'client_slug' => $deployment->client_slug,
                        'chat_id' => $chatId
                    ]);
                }
            } else {
                // If no chat ID, just mark as sent to avoid repeated useless checks
                $deployment->update(['reminder_3_days_sent' => true]);
                Log::channel('deploy-audit')->warning('Skipped 3 days reminder due to missing client chat ID.', [
                    'deployment_id' => $deployment->id,
                    'client_slug' => $deployment->client_slug
                ]);
            }
        }

        // 2. Check for 1 day reminder
        $reminder1Day = Deployment::where('status', DeploymentStatus::ACTIVE)
            ->where('reminder_1_day_sent', false)
            ->where('expires_at', '<=', $oneDayAhead)
            ->get();

        foreach ($reminder1Day as $deployment) {
            $chatId = $this->getClientChatId($deployment);
            if ($chatId) {
                $expiryFormatted = $deployment->expires_at->format('d-m-Y H:i');
                $message = "⚠️ <b>PERINGATAN KEDALUWARSA</b>\n\nHalo! Layanan deployment Anda untuk subdomain <b>{$deployment->client_slug}</b> akan berakhir <b>BESOK</b> (pada <b>{$expiryFormatted}</b>).\n\nSegera hubungi Admin (<b>@awbuilderadmin</b>) untuk melakukan perpanjangan agar sistem Anda tidak dimatikan dan diarsipkan secara otomatis.";
                
                $this->info("Sending 1 day reminder to client chat ID {$chatId} for {$deployment->client_slug}...");
                if ($botService->sendMessage($chatId, $message)) {
                    $deployment->update(['reminder_1_day_sent' => true]);
                    Log::channel('deploy-audit')->info('Sent 1 day expiry reminder.', [
                        'deployment_id' => $deployment->id,
                        'client_slug' => $deployment->client_slug,
                        'chat_id' => $chatId
                    ]);
                }
            } else {
                // If no chat ID, just mark as sent to avoid repeated useless checks
                $deployment->update(['reminder_1_day_sent' => true]);
                Log::channel('deploy-audit')->warning('Skipped 1 day reminder due to missing client chat ID.', [
                    'deployment_id' => $deployment->id,
                    'client_slug' => $deployment->client_slug
                ]);
            }
        }

        $this->info('Expiry reminders processing completed.');
        Log::channel('deploy-audit')->info('Scheduled checks for expiry reminders completed.');

        return Command::SUCCESS;
    }

    /**
     * Helper to extract client Telegram Chat ID from lead reference.
     */
    protected function getClientChatId(Deployment $deployment): ?string
    {
        if ($deployment->source === 'telegram' && str_starts_with($deployment->lead_reference, 'tg_')) {
            $parts = explode('_', $deployment->lead_reference);
            return $parts[1] ?? null;
        }
        return null;
    }
}
