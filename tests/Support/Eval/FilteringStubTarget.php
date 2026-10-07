<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Support\Eval;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\FiltersDatasetRows;

class FilteringStubTarget extends TextStubTarget implements FiltersDatasetRows
{
    public function key(): string
    {
        return 'stub-filter';
    }

    public function includeRow(array $row): bool
    {
        return ($row['expected'] ?? '') !== '';
    }
}
