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
        Schema::table('deployments', function (Blueprint $table) {
            $table->string('client_token')->nullable()->unique();
            $table->string('custom_domain')->nullable()->unique();
            $table->boolean('reminder_3_days_sent')->default(false);
            $table->boolean('reminder_1_day_sent')->default(false);
            $table->double('cpu_usage')->default(0.0);
            $table->double('ram_usage')->default(0.0);
            $table->double('disk_usage')->default(0.0);
            $table->timestamp('last_monitored_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn([
                'client_token',
                'custom_domain',
                'reminder_3_days_sent',
                'reminder_1_day_sent',
                'cpu_usage',
                'ram_usage',
                'disk_usage',
                'last_monitored_at'
            ]);
        });
    }
};
