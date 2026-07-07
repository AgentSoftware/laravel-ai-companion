<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Support\Eval;

use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

#[Provider([Lab::Anthropic])]
#[UseCheapestModel]
class ToolStubAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'stub';
    }

    public function tools(): iterable
    {
        return [new LookupStubTool];
    }
}
