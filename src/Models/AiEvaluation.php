<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $ai_response_log_id
 * @property string $agent
 * @property string|null $scorer
 * @property int $overall_score
 * @property array<int, array{name: string, score: int, feedback: string}> $criteria
 * @property string $summary
 * @property string $judge_model
 */
class AiEvaluation extends Model
{
    use HasUuids;

    protected $fillable = [
        'ai_response_log_id',
        'agent',
        'scorer',
        'overall_score',
        'criteria',
        'summary',
        'judge_model',
    ];

    protected $casts = [
        'criteria' => 'array',
    ];

    /** @return BelongsTo<AiResponseLog, $this> */
    public function log(): BelongsTo
    {
        return $this->belongsTo(AiResponseLog::class, 'ai_response_log_id');
    }
}
