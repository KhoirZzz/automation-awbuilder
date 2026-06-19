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
        'template_path',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get deployments for this template.
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }
}
