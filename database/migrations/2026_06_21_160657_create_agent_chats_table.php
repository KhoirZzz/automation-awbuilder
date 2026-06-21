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
        Schema::create('agent_chats', function (Blueprint $table) {
            $table->id();
            $table->string('role');
            $table->longText('content');
            $table->boolean('is_error')->default(false);
            $table->boolean('is_deploying')->default(false);
            $table->string('url')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_chats');
    }
};
