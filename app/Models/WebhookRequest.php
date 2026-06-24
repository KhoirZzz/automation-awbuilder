<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'ip_address',
        'source',
        'payload_hash',
        'payload',
        'status_code',
        'validation_status',
    ];
}
