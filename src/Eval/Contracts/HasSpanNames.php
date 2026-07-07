<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Contracts;

interface HasSpanNames
{
    /**
     * The exact Braintrust span names the online scoring rule should attach to.
     *
     * Live agent spans are named `class_basename($agent)`. ai:publish-eval defaults
     * an online rule to `Str::studly(key())` and `Str::studly(key()).'Agent'`;
     * implement this on an EvalTarget whose agent class name doesn't follow that
     * convention (e.g. key `block-composer` but agent `EmailBlockComposerAgent`) so
     * the rule matches live traffic instead of silently scoring nothing.
     *
     * @return array<int, string>
     */
    public function spanNames(): array;
}
