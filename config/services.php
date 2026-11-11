<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
        'webhook_url' => env('SLACK_WEBHOOK_URL'),
    ],

    /*
    | Gemini on Vertex AI. Credentials are never configured here: the SDK finds
    | them through Application Default Credentials (gcloud locally, the service
    | account on Cloud Run).
    */
    'vertex_ai' => [
        'project' => env('VERTEX_AI_PROJECT'),
        'location' => env('VERTEX_AI_LOCATION', 'global'),
        'model' => env('VERTEX_AI_MODEL', 'gemini-3.8-flash'),
        'max_output_tokens' => (int) env('VERTEX_AI_MAX_OUTPUT_TOKENS', 16384),
        'temperature' => (float) env('VERTEX_AI_TEMPERATURE', 0.2),
        // Tokens the model may spend reasoning before it answers (billed as output). 0 turns it off; empty leaves the model default.
        'thinking_budget' => env('VERTEX_AI_THINKING_BUDGET', 0),
        // Seconds. Writing a whole deployment takes longer than the 30 s Octane gives a request
        // (config/octane.php, max_execution_time): call the agent from a command or a queued job.
        'timeout' => (int) env('VERTEX_AI_TIMEOUT', 120),
    ],

    'cloud_architect' => [
        'max_files' => (int) env('CLOUD_ARCHITECT_MAX_FILES', 300),
        'max_manifest_characters' => (int) env('CLOUD_ARCHITECT_MAX_MANIFEST_CHARACTERS', 6000),
    ],

];
