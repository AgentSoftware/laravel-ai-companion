<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiTokenTracker\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

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
    ];
}
