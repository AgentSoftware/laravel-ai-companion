<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Models;

use AgentSoftware\LaravelAiCompanion\Enums\AiResponseStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string|null $invocation_id
 * @property string $agent
 * @property string|null $instructions
 * @property string $prompt
 * @property array<string, mixed>|null $response
 * @property array<string, mixed>|null $properties
 * @property array<string, mixed>|null $metadata
 * @property AiResponseStatus $status
 * @property int|null $duration_ms
 */
class AiResponseLog extends Model
{
    use HasUuids;
    use MassPrunable;

    protected $fillable = [
        'invocation_id',
        'agent',
        'instructions',
        'prompt',
        'response',
        'properties',
        'metadata',
        'status',
        'duration_ms',
    ];

    protected $casts = [
        'response' => 'array',
        'properties' => 'array',
        'metadata' => 'array',
        'status' => AiResponseStatus::class,
    ];

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        $months = (int) config('ai-companion.response_logs.prune_after_months', 6);

        return static::query()->where('created_at', '<=', now()->subMonths($months));
    }
}
