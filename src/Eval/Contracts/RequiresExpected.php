<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Contracts;

/**
 * Marker for scorers that compare against expected/dataset-only context.
 * Online scoring skips them — live traffic has no expected answer.
 */
interface RequiresExpected {}
