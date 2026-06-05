# Laravel AI Companion

A companion package for the [Laravel AI SDK](https://laravel.com/docs/ai-sdk). Two capabilities in one install:

1. **Token usage tracking** — automatic, global. Every `AgentPrompted` event writes one row to `ai_token_usages`.
2. **Response logging** — opt-in, per agent. Attach the `LogAiResponse` middleware to capture prompt/response/metadata to `ai_response_logs`.

## Installation

```bash
composer require agentsoftware/laravel-ai-companion
php artisan vendor:publish --tag="ai-companion-config"
php artisan migrate
```

The package auto-registers itself.

## Token usage tracking

```php
use AgentSoftware\LaravelAiCompanion\Facades\AiUsage;

// Overall totals
AiUsage::total();
// ['input_tokens' => 12400, 'output_tokens' => 3200, 'cache_write_tokens' => 800, 'cache_read_tokens' => 400]

// Totals for a specific agent
AiUsage::forAgent(MyAgent::class)->total();

// Totals grouped by agent class
AiUsage::byAgent();

// Totals scoped to a source (see "Source attribution" below)
AiUsage::forSource('session-abc')->total();
```

### Source attribution

To group token usage by a domain object (e.g. an onboarding session), set the source on the Laravel `Context` before prompting:

```php
use Illuminate\Support\Facades\Context;

Context::add('ai_usage_source_id', $session->id);
Context::add('ai_usage_source_model', $session::class);
```

The `source()` morph relation on `AiTokenUsage` lets you load the originating model directly.

## Response logging

Opt agents in by implementing `Laravel\Ai\Contracts\HasMiddleware` and adding `LogAiResponse`:

```php
use AgentSoftware\LaravelAiCompanion\Middleware\LogAiResponse;
use Laravel\Ai\Contracts\HasMiddleware;

class SegmentBuilderAgent implements Agent, HasMiddleware
{
    public function middleware(): array
    {
        return [new LogAiResponse];
    }
}
```

Each prompt writes a row to `ai_response_logs` with the prompt text, structured/text response, provider metadata, status (`running`/`success`/`failure`), and `duration_ms`.

To attach domain context (e.g. user/company) without relying on `Auth::user()` — which doesn't work in queued or CLI contexts — implement `HasLoggableProperties`:

```php
use AgentSoftware\LaravelAiCompanion\Contracts\HasLoggableProperties;

class SegmentBuilderAgent implements Agent, HasMiddleware, HasLoggableProperties
{
    public function loggableProperties(): array
    {
        return [
            'company_id' => $this->company->id,
            'user_id' => Auth::id(),
        ];
    }
}
```

The returned array is stored in the `properties` JSON column.

### Pruning

`AiResponseLog` uses `MassPrunable`. The service provider registers a daily `model:prune` schedule when pruning is enabled.

Configure via env:

```env
AI_COMPANION_PRUNE_ENABLED=true     # default true
AI_COMPANION_PRUNE_MONTHS=6         # default 6
AI_COMPANION_PRUNE_SCHEDULE="0 3 * * *"  # default 03:00 daily
```

## How it works

- Token tracking listens to the `AgentPrompted` event dispatched by `laravel/ai`. One row per prompt, always.
- Response logging hooks into the agent middleware pipeline. The middleware writes a `running` row before calling the agent, updates it to `success`/`failure` after, and records `duration_ms`.

The two tables stay independent — token tracking works without response logging, and vice versa.

## Requirements

- PHP 8.4+
- Laravel 12 or 13
- `laravel/ai`
