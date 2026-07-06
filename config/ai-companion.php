<?php

declare(strict_types=1);

return [
    'response_logs' => [
        'prune_enabled' => env('AI_COMPANION_PRUNE_ENABLED', true),
        'prune_after_months' => env('AI_COMPANION_PRUNE_MONTHS', 6),
        'prune_schedule' => env('AI_COMPANION_PRUNE_SCHEDULE', '0 3 * * *'),
    ],

    'tracing' => [
        // Trace exporter driver. Ships with 'braintrust'; register more on the
        // TraceExporterManager (createXxxDriver / ::extend()).
        'exporter' => env('AI_COMPANION_TRACING_EXPORTER', 'braintrust'),
    ],

    'braintrust' => [
        'enabled' => env('AI_COMPANION_BRAINTRUST_ENABLED', false),
        'api_key' => env('BRAINTRUST_API_KEY'),
        'api_url' => env('BRAINTRUST_API_URL', 'https://api.braintrust.dev'),
        // Braintrust project name. Defaults to the app name at runtime when null.
        'project' => env('BRAINTRUST_PROJECT'),
        'queue' => [
            'connection' => env('AI_COMPANION_BRAINTRUST_QUEUE_CONNECTION'),
            'queue' => env('AI_COMPANION_BRAINTRUST_QUEUE'),
        ],
    ],

    'eval' => [
        // Experiment exporter driver. Ships with 'braintrust'; register more on
        // the ExperimentExporterManager (createXxxDriver / ::extend()).
        'exporter' => env('AI_COMPANION_EVAL_EXPORTER', 'braintrust'),
        // EvalHarness implementation that boots a throwaway world per dataset row.
        'harness' => null,
        // EvalTarget class names the run command can evaluate.
        'targets' => [],
        // LLM-judge provider/model overrides. Null uses the JudgeAgent default
        // (cheapest Anthropic model).
        'judge' => [
            'provider' => env('AI_COMPANION_EVAL_JUDGE_PROVIDER'),
            'model' => env('AI_COMPANION_EVAL_JUDGE_MODEL'),
        ],
        // Where scored NDJSON is written when no Braintrust key is set.
        'output_path' => storage_path('app/braintrust'),
        // Where the scaffold command looks for Agent implementations.
        'scaffold' => [
            'agent_path' => null,      // defaults to app_path() at runtime
            'agent_namespace' => null, // defaults to the app namespace at runtime
        ],

        // Online scoring: run local PHP scorers against recent production spans on
        // a schedule and merge scores back onto the Braintrust spans.
        'online' => [
            'enabled' => env('AI_COMPANION_ONLINE_SCORING_ENABLED', false),
            'schedule' => env('AI_COMPANION_ONLINE_SCORING_SCHEDULE', '*/15 * * * *'),
            'lookback_minutes' => env('AI_COMPANION_ONLINE_SCORING_LOOKBACK', 60),
            // EvalTarget class => sample rate (0.0–1.0 of matching spans scored).
            'targets' => [],
        ],
    ],
];
