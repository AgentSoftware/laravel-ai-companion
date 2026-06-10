<?php

declare(strict_types=1);

return [
    'response_logs' => [
        'prune_enabled' => env('AI_COMPANION_PRUNE_ENABLED', true),
        'prune_after_months' => env('AI_COMPANION_PRUNE_MONTHS', 6),
        'prune_schedule' => env('AI_COMPANION_PRUNE_SCHEDULE', '0 3 * * *'),
    ],

    'braintrust' => [
        'enabled' => env('AI_COMPANION_BRAINTRUST_ENABLED', false),
        'api_key' => env('BRAINTRUST_API_KEY'),
        'api_url' => env('BRAINTRUST_API_URL', 'https://api.braintrust.dev'),
        // Braintrust project name. Defaults to the app name at runtime when null.
        'project' => env('BRAINTRUST_PROJECT'),
        // Insert requests are chunked to stay under Braintrust's 20mb payload cap.
        'max_payload_bytes' => (int) env('AI_COMPANION_BRAINTRUST_MAX_PAYLOAD_BYTES', 10_000_000),
        'queue' => [
            'connection' => env('AI_COMPANION_BRAINTRUST_QUEUE_CONNECTION'),
            'queue' => env('AI_COMPANION_BRAINTRUST_QUEUE'),
        ],
    ],
];
