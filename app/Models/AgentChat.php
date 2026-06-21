<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentChat extends Model
{
    protected $table = 'agent_chats';

    protected $fillable = [
        'role',
        'content',
        'is_error',
        'is_deploying',
        'url',
    ];

    protected $casts = [
        'is_error' => 'boolean',
        'is_deploying' => 'boolean',
    ];
}
