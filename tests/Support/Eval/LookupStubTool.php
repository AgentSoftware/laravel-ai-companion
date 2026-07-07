<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Support\Eval;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class LookupStubTool implements Tool
{
    public function description(): string
    {
        return 'Looks up a stub record.';
    }

    public function handle(Request $request): string
    {
        // Long enough to exceed the runner's transcript result cap.
        return str_repeat('x', 600);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'postcode' => $schema->string()->required(),
        ];
    }
}
