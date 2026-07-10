<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Facades;

use AgentSoftware\LaravelAiCompanion\Feedback\BraintrustFeedbackClient;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void record(string $sourceModel, string $sourceId, bool $good, ?string $comment = null)
 *
 * @see BraintrustFeedbackClient
 */
class AiFeedback extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BraintrustFeedbackClient::class;
    }
}
