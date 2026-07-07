<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Support\Eval;

use AgentSoftware\LaravelAiCompanion\Eval\Commands\RunEvalCommand;

class StubEvalCommand extends RunEvalCommand
{
    /** @var string */
    protected $signature = 'stub:eval {target?} {--dataset=} {--out=} {--provider=} {--model=} {--tag=} {--limit=} {--trials=1} {--concurrency=5}';
}
