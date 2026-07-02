<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\Scaffolding\FixtureAgent;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(base_path('database/eval-datasets'));
    File::deleteDirectory(app_path('Ai'));
});

it('scaffolds a dataset and eval target from response logs', function (): void {
    AiResponseLog::create([
        'agent' => FixtureAgent::class,
        'prompt' => 'Plan pages for acme.com',
        'response' => ['text' => 'the plan'],
        'properties' => ['company_brand_tone' => 'friendly'],
        'status' => 'success',
    ]);

    // Point discovery at the test Support dir where FixtureAgent lives.
    config()->set('ai-companion.eval.scaffold.agent_path', dirname(__DIR__, 3).'/Support');
    config()->set('ai-companion.eval.scaffold.agent_namespace', 'AgentSoftware\\LaravelAiCompanion\\Tests\\Support\\');

    $this->artisan('ai:eval:scaffold')
        ->expectsQuestion('Which agent is this eval for?', FixtureAgent::class)
        ->expectsQuestion('Eval key', 'fixture-agent')
        ->expectsQuestion('Eval label', 'Fixture Agent')
        ->expectsQuestion('Where should the dataset come from?', 'response_logs')
        ->expectsQuestion('How many rows?', '50')
        ->expectsQuestion('Include in each row (prompt is always included)', ['expected', 'metadata'])
        ->expectsQuestion('Built-in scorers', ['llm_judge'])
        ->expectsQuestion('LLM judge name', 'quality')
        ->expectsQuestion('LLM judge rubric', 'Is the plan complete and on-brand?')
        ->expectsQuestion('Custom scorer class names (comma-separated, blank for none)', 'NoHallucinatedUrlsScorer')
        ->assertSuccessful();

    $dataset = base_path('database/eval-datasets/fixture-agent.json');
    expect(File::exists($dataset))->toBeTrue();

    $rows = File::json($dataset);
    expect($rows[0]['prompt'])->toBe('Plan pages for acme.com')
        ->and($rows[0]['expected'])->toBe(['text' => 'the plan'])
        ->and($rows[0]['company_brand_tone'])->toBe('friendly');

    $target = app_path('Ai/Eval/Targets/FixtureAgentEvalTarget.php');
    expect(File::exists($target))->toBeTrue()
        ->and(File::get($target))->toContain("companyBrandTone: (string) (\$row['company_brand_tone'] ?? '')")
        ->and(File::get($target))->toContain("new LlmJudgeScorer(name: 'quality', rubric: 'Is the plan complete and on-brand?')")
        ->and(File::get($target))->toContain('new NoHallucinatedUrlsScorer');

    expect(File::exists(app_path('Ai/Eval/Scorers/NoHallucinatedUrlsScorer.php')))->toBeTrue();
});

it('fails softly when no agents are found', function (): void {
    config()->set('ai-companion.eval.scaffold.agent_path', sys_get_temp_dir().'/empty-'.uniqid());
    config()->set('ai-companion.eval.scaffold.agent_namespace', 'App\\');

    $this->artisan('ai:eval:scaffold')->assertFailed();
});
