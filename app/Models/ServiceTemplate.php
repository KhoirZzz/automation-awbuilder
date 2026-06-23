<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'category',
        'template_path',
        'is_active',
        'price',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted()
    {
        static::created(function ($template) {
            if (!app()->runningUnitTests()) {
                \App\Jobs\DeployDemoInstanceJob::dispatch($template);
            }
        });
    }

    /**
     * Get deployments for this template.
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }
}
