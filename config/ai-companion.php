<?php

declare(strict_types=1);

return [
    'response_logs' => [
        'prune_enabled' => env('AI_COMPANION_PRUNE_ENABLED', true),
        'prune_after_months' => env('AI_COMPANION_PRUNE_MONTHS', 6),
        'prune_schedule' => env('AI_COMPANION_PRUNE_SCHEDULE', '0 3 * * *'),
    ],
];
