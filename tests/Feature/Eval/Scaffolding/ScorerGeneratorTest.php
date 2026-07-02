<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\ScorerGenerator;

it('renders a scorer stub with namespace and class substituted', function (): void {
    $code = new ScorerGenerator()->generate('App\\Ai\\Eval\\Scorers', 'NoHallucinatedUrlsScorer');

    expect($code)
        ->toContain('declare(strict_types=1);')
        ->toContain('namespace App\\Ai\\Eval\\Scorers;')
        ->toContain('final class NoHallucinatedUrlsScorer implements Scorer')
        ->toContain('public function score(EvalSubject $subject): Score')
        ->toContain('TODO');
});
