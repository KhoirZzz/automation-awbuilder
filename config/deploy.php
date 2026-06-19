<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Deployment Paths
    |--------------------------------------------------------------------------
    |
    | Define paths for template bases, instance bases, and archiving.
    | template_base_path: path to the base templates folder
    | instance_base_path: where client instances are deployed (MUST be outside public/)
    | archive_path: where expired instances are archived
    |
    */
    'template_base_path' => env('DEPLOY_TEMPLATE_BASE_PATH', storage_path('templates')),
    'instance_base_path' => env('DEPLOY_INSTANCE_BASE_PATH', '/var/www/deployments'),
    'archive_path' => env('DEPLOY_ARCHIVE_PATH', '/var/www/deployments_archive'),

    /*
    |--------------------------------------------------------------------------
    | Reserved Subdomains / Slug Names
    |--------------------------------------------------------------------------
    |
    | Whitelist of reserved words that cannot be used as client_slug.
    |
    */
    'reserved_slugs' => [
        'www', 'admin', 'api', 'mail', 'app', 'dev', 'test', 'status', 'portal',
        'dashboard', 'system', 'root', 'administrator', 'webmaster', 'support',
        'billing', 'payment', 'secure', 'auth', 'login', 'register', 'signup'
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Agent Passkey
    |--------------------------------------------------------------------------
    |
    | 6-digit code required to chat with the AI Agent in the playground.
    |
    */
    'agent_passkey' => env('AGENT_PASSKEY', '852963'),
];
