<?php

declare(strict_types=1);

return [
    'response_logs' => [
        'prune_enabled' => env('AI_COMPANION_PRUNE_ENABLED', true),
        'prune_after_months' => env('AI_COMPANION_PRUNE_MONTHS', 6),
        'prune_schedule' => env('AI_COMPANION_PRUNE_SCHEDULE', '0 3 * * *'),
    ],

    'evaluation' => [
        'enabled' => env('AI_EVALUATION_ENABLED', true),

        /*
         | The model used by the LLM judge. A cheaper/faster model (Haiku-class)
         | is recommended since it runs once per log evaluated.
         */
        'model' => env('AI_EVALUATION_MODEL', 'claude-haiku-4-5-20251001'),

        /*
         | Register Scorer subclasses here to provide explicit evaluation criteria
         | for specific agents. Agents with no registered scorer use auto-inferred
         | criteria based on their stored instructions.
         |
         | Example:
         | 'scorers' => [
         |     App\Ai\Scorers\ContentWriterAgentScorer::class,
         | ],
         */
        'scorers' => [],
    ],
];
