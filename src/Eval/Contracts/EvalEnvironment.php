<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Contracts;

/**
 * Marker for the throwaway world an {@see EvalHarness} boots for one dataset
 * row. The package treats it as opaque; the host app's harness returns a
 * concrete implementation that its targets cast back to.
 */
interface EvalEnvironment {}
