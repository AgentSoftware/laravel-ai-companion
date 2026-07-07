<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $ai_response_log_id
 * @property string|null $tool_invocation_id
 * @property string $tool
 * @property array<string, mixed> $input
 * @property mixed $output
 * @property int|null $duration_ms
 */
class AiToolCall extends Model
{
    use HasUuids;

    protected $fillable = [
        'ai_response_log_id',
        'tool_invocation_id',
        'tool',
        'input',
        'output',
        'duration_ms',
    ];

    protected $casts = [
        'input' => 'array',
        'output' => 'array',
    ];

    /** @return BelongsTo<AiResponseLog, $this> */
    public function responseLog(): BelongsTo
    {
        return $this->belongsTo(AiResponseLog::class, 'ai_response_log_id', 'id');
    }
}
