<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('webhook_requests', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address');
            $table->string('source'); // telegram / whatsapp
            $table->string('payload_hash')->index();
            $table->text('payload')->nullable();
            $table->integer('status_code')->default(200);
            $table->string('validation_status')->default('success'); // success, duplicate, invalid, unauthorized, rate_limited
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_requests');
    }
};
