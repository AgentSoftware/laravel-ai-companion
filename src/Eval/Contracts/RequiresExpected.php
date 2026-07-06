<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Contracts;

/**
 * Marker for scorers that need context only dataset/offline runs can supply
 * (expected answers, captured tool calls). Online scoring skips them — live
 * spans carry neither.
 */
interface RequiresExpected {}
