<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Support\Eval;

use AgentSoftware\LaravelAiCompanion\Eval\Commands\RunEvalCommand;
use Illuminate\Console\Attributes\Signature;

#[Signature('stub:eval {target?} {--dataset=} {--out=} {--provider=} {--model=} {--tag=} {--limit=} {--trials=1}')]
class StubEvalCommand extends RunEvalCommand {}
