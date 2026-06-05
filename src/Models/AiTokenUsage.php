<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $id
 * @property string $agent
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_write_tokens
 * @property int $cache_read_tokens
 * @property string|null $source_id
 * @property string|null $source_model
 */
class AiTokenUsage extends Model
{
    use HasUuids;

    protected $fillable = [
        'agent',
        'model',
        'input_tokens',
        'output_tokens',
        'cache_write_tokens',
        'cache_read_tokens',
        'source_id',
        'source_model',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'source_model', 'source_id');
    }
}
