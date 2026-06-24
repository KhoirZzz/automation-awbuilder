<?php

namespace App\Models;

use App\Enums\DeploymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deployment extends Model
{
    use HasFactory;

    protected $fillable = [
        'source',
        'lead_reference',
        'service_template_id',
        'client_slug',
        'instance_path',
        'started_at',
        'expires_at',
        'status',
        'price',
        'raw_llm_response',
        'client_token',
        'custom_domain',
        'reminder_3_days_sent',
        'reminder_1_day_sent',
        'cpu_usage',
        'ram_usage',
        'disk_usage',
        'last_monitored_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'status' => DeploymentStatus::class,
        'reminder_3_days_sent' => 'boolean',
        'reminder_1_day_sent' => 'boolean',
        'cpu_usage' => 'double',
        'ram_usage' => 'double',
        'disk_usage' => 'double',
        'last_monitored_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function ($deployment) {
            if (empty($deployment->client_token)) {
                $deployment->client_token = \Illuminate\Support\Str::random(32);
            }
        });
    }

    /**
     * Get the service template for this deployment.
     */
    public function serviceTemplate(): BelongsTo
    {
        return $this->belongsTo(ServiceTemplate::class);
    }
}
