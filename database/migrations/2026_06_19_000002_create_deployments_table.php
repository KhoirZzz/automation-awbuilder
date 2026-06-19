<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->string('source'); // telegram / whatsapp
            $table->string('lead_reference')->nullable();
            $table->foreignId('service_template_id')->constrained('service_templates');
            $table->string('client_slug');
            $table->string('instance_path');
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->string('status'); // pending, active, expired, failed
            $table->integer('price')->nullable();
            $table->text('raw_llm_response')->nullable();
            $table->timestamps();
        });

        // Create partial unique index
        DB::statement("CREATE UNIQUE INDEX deployments_client_slug_active_unique ON deployments (client_slug) WHERE status IN ('active', 'pending')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deployments');
    }
};
