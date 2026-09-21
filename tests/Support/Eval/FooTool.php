<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Support\Eval;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class FooTool implements Tool
{
    public function description(): string
    {
        return 'Does a foo.';
    }

    public function handle(Request $request): string
    {
        return 'foo done';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
