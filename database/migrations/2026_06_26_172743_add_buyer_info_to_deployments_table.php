<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds buyer-specific columns for direct web payment flow:
     * - buyer_telegram_chat_id: to notify buyer when payment verified
     * - buyer_telegram_token: buyer's bot token for sending notifications
     * - expected_price: price the system expects to be paid (in IDR)
     * - payment_verified_at: timestamp when LLM verified the payment proof
     */
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->string('buyer_telegram_chat_id')->nullable()->after('raw_llm_response');
            $table->string('buyer_telegram_token')->nullable()->after('buyer_telegram_chat_id');
            $table->unsignedInteger('expected_price')->nullable()->after('buyer_telegram_token');
            $table->timestamp('payment_verified_at')->nullable()->after('expected_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn([
                'buyer_telegram_chat_id',
                'buyer_telegram_token',
                'expected_price',
                'payment_verified_at',
            ]);
        });
    }
};
