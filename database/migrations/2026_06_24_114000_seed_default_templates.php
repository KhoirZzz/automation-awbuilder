<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\ServiceTemplate;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        // Seed default templates if they don't exist
        $defaults = [
            [
                'key' => 'shopee-bot',
                'name' => 'Shopee Bot Responder',
                'template_path' => 'shopee-bot',
                'category' => 'telegram',
                'price' => 100000,
                'is_active' => true,
            ],
            [
                'key' => 'wa-responder',
                'name' => 'WhatsApp Responder',
                'template_path' => 'wa-responder',
                'category' => 'whatsapp',
                'price' => 120000,
                'is_active' => true,
            ],
            [
                'key' => 'shopee-spm',
                'name' => 'Shopee SPM Link Image-to-PDF',
                'template_path' => 'shopee-spm',
                'category' => 'marketing',
                'price' => 150000,
                'is_active' => true,
            ],
        ];

        foreach ($defaults as $data) {
            ServiceTemplate::updateOrCreate(
                ['key' => $data['key']],
                $data
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        ServiceTemplate::whereIn('key', ['shopee-bot', 'wa-responder', 'shopee-spm'])->delete();
    }
};
