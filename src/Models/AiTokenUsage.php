<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiTokenTracker\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $agent
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_write_tokens
 * @property int $cache_read_tokens
 * @property string|null $source_id
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
    ];
}
