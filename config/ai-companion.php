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
         | The provider and model used by the LLM judge. Set AI_EVALUATION_PROVIDER
         | to any provider key configured in config/ai.php (e.g. 'anthropic', 'gemini').
         | The model must belong to that provider.
         | A cheaper/faster model is recommended since it runs once per log evaluated.
         */
        'provider' => env('AI_EVALUATION_PROVIDER', 'gemini'),
        'model'    => env('AI_EVALUATION_MODEL', 'gemini-2.0-flash'),

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
