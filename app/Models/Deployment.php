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
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'status' => DeploymentStatus::class,
    ];

    /**
     * Get the service template for this deployment.
     */
    public function serviceTemplate(): BelongsTo
    {
        return $this->belongsTo(ServiceTemplate::class);
    }
}
