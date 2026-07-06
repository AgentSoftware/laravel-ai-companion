<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;
use AgentSoftware\LaravelAiCompanion\Tests\Support\Eval\Scaffolding\FixtureAgent;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    File::deleteDirectory(base_path('database/eval-datasets'));
    File::deleteDirectory(app_path('Ai'));
    File::deleteDirectory(base_path('resources/ai/scorers'));

    config()->set('ai-companion.eval.scaffold.agent_path', dirname(__DIR__, 3).'/Support');
    config()->set('ai-companion.eval.scaffold.agent_namespace', 'AgentSoftware\\LaravelAiCompanion\\Tests\\Support\\');
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

    $this->artisan('ai:scaffold-eval')
        // search() falls back to two questions in tests: the term, then the pick.
        ->expectsQuestion('Which agent is this eval for?', 'FixtureAgent')
        ->expectsQuestion('Which agent is this eval for?', FixtureAgent::class)
        ->expectsQuestion('Eval key', 'fixture-agent')
        ->expectsQuestion('Eval label', 'Fixture Agent')
        ->expectsQuestion('Where should the test data come from?', 'response_logs')
        ->expectsQuestion('How many past interactions should the dataset hold?', '50')
        ->expectsQuestion('Each row always gets the prompt. What else should it keep?', ['expected', 'metadata'])
        ->expectsQuestion('Which built-in scorers should judge the answers?', ['llm_judge'])
        ->expectsQuestion('LLM judge name', 'quality')
        ->expectsQuestion('LLM judge rubric', 'Is the plan complete and on-brand?')
        // Duplicates (slugged) are deduped; invalid names are skipped.
        ->expectsQuestion('Custom scorer names (comma-separated, blank for none)', 'NoHallucinatedUrls, no-hallucinated-urls, 123bad')
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
        ->and(File::get($target))->toContain("new JsScorer(base_path('resources/ai/scorers/no-hallucinated-urls.js'))");

    expect(File::exists(base_path('resources/ai/scorers/no-hallucinated-urls.js')))->toBeTrue()
        ->and(substr_count(File::get($target), 'new JsScorer'))->toBe(1)
        ->and(File::exists(base_path('resources/ai/scorers/123bad.js')))->toBeFalse();
});

it('fails softly when no agents are found', function (): void {
    config()->set('ai-companion.eval.scaffold.agent_path', sys_get_temp_dir().'/empty-'.uniqid());
    config()->set('ai-companion.eval.scaffold.agent_namespace', 'App\\');

    $this->artisan('ai:scaffold-eval')->assertFailed();
});

it('skips dataset building entirely and still writes an eval target', function (): void {
    $this->artisan('ai:scaffold-eval')
        ->expectsQuestion('Which agent is this eval for?', 'FixtureAgent')
        ->expectsQuestion('Which agent is this eval for?', FixtureAgent::class)
        ->expectsQuestion('Eval key', 'fixture-agent')
        ->expectsQuestion('Eval label', 'Fixture Agent')
        ->expectsQuestion('Where should the test data come from?', 'skip')
        ->expectsQuestion('Which built-in scorers should judge the answers?', [])
        ->expectsQuestion('Custom scorer names (comma-separated, blank for none)', '')
        ->assertSuccessful();

    expect(File::exists(base_path('database/eval-datasets/fixture-agent.json')))->toBeFalse()
        ->and(File::exists(app_path('Ai/Eval/Targets/FixtureAgentEvalTarget.php')))->toBeTrue();
});

it('errors when braintrust is not configured for a braintrust source', function (): void {
    config()->set('ai-companion.braintrust.api_key', null);

    $this->artisan('ai:scaffold-eval')
        ->expectsQuestion('Which agent is this eval for?', 'FixtureAgent')
        ->expectsQuestion('Which agent is this eval for?', FixtureAgent::class)
        ->expectsQuestion('Eval key', 'fixture-agent')
        ->expectsQuestion('Eval label', 'Fixture Agent')
        ->expectsQuestion('Where should the test data come from?', 'braintrust_logs')
        ->assertFailed();

    expect(File::exists(base_path('database/eval-datasets/fixture-agent.json')))->toBeFalse();
});

it('scaffolds a dataset from braintrust production logs', function (): void {
    config()->set('ai-companion.braintrust.api_url', 'https://api.braintrust.dev');
    config()->set('ai-companion.braintrust.api_key', 'test-key');
    config()->set('ai-companion.braintrust.project', 'my-project');

    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1']),
        'api.braintrust.dev/btql' => Http::response(['data' => [
            [
                'input' => ['prompt' => 'Plan pages for acme.com', 'instructions' => 'You are a page planner.'],
                'output' => ['text' => 'the plan'],
                'span_attributes' => ['name' => 'FixtureAgent'],
                'metadata' => ['company_brand_tone' => 'friendly'],
            ],
        ]]),
    ]);

    $this->artisan('ai:scaffold-eval')
        ->expectsQuestion('Which agent is this eval for?', 'FixtureAgent')
        ->expectsQuestion('Which agent is this eval for?', FixtureAgent::class)
        ->expectsQuestion('Eval key', 'fixture-agent')
        ->expectsQuestion('Eval label', 'Fixture Agent')
        ->expectsQuestion('Where should the test data come from?', 'braintrust_logs')
        ->expectsQuestion('How many past interactions should the dataset hold?', '10')
        ->expectsQuestion('Each row always gets the prompt. What else should it keep?', ['expected', 'metadata'])
        ->expectsQuestion('Which built-in scorers should judge the answers?', [])
        ->expectsQuestion('Custom scorer names (comma-separated, blank for none)', '')
        ->assertSuccessful();

    $rows = File::json(base_path('database/eval-datasets/fixture-agent.json'));
    expect($rows[0]['prompt'])->toBe('Plan pages for acme.com')
        ->and($rows[0]['company_brand_tone'])->toBe('friendly');
});

it('scaffolds a dataset from a picked braintrust dataset', function (): void {
    config()->set('ai-companion.braintrust.api_url', 'https://api.braintrust.dev');
    config()->set('ai-companion.braintrust.api_key', 'test-key');
    config()->set('ai-companion.braintrust.project', 'my-project');

    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1']),
        'api.braintrust.dev/v1/dataset?*' => Http::response(['objects' => [
            ['id' => 'ds-1', 'name' => 'page-planner'],
        ]]),
        'api.braintrust.dev/v1/dataset/ds-1/fetch' => Http::response(['events' => [
            [
                'input' => 'Plan pages for acme.com',
                'expected' => ['text' => 'the plan'],
                'metadata' => ['company_brand_tone' => 'friendly'],
            ],
        ]]),
    ]);

    $this->artisan('ai:scaffold-eval')
        ->expectsQuestion('Which agent is this eval for?', 'FixtureAgent')
        ->expectsQuestion('Which agent is this eval for?', FixtureAgent::class)
        ->expectsQuestion('Eval key', 'fixture-agent')
        ->expectsQuestion('Eval label', 'Fixture Agent')
        ->expectsQuestion('Where should the test data come from?', 'braintrust_dataset')
        ->expectsQuestion('Which Braintrust dataset?', 'ds-1')
        ->expectsQuestion('How many past interactions should the dataset hold?', '10')
        ->expectsQuestion('Each row always gets the prompt. What else should it keep?', ['expected', 'metadata'])
        ->expectsQuestion('Which built-in scorers should judge the answers?', [])
        ->expectsQuestion('Custom scorer names (comma-separated, blank for none)', '')
        ->assertSuccessful();

    $rows = File::json(base_path('database/eval-datasets/fixture-agent.json'));
    expect($rows[0]['prompt'])->toBe('Plan pages for acme.com')
        ->and($rows[0]['company_brand_tone'])->toBe('friendly');
});

it('errors when no braintrust datasets exist to pick from', function (): void {
    config()->set('ai-companion.braintrust.api_url', 'https://api.braintrust.dev');
    config()->set('ai-companion.braintrust.api_key', 'test-key');
    config()->set('ai-companion.braintrust.project', 'my-project');

    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1']),
        'api.braintrust.dev/v1/dataset?*' => Http::response(['objects' => []]),
    ]);

    $this->artisan('ai:scaffold-eval')
        ->expectsQuestion('Which agent is this eval for?', 'FixtureAgent')
        ->expectsQuestion('Which agent is this eval for?', FixtureAgent::class)
        ->expectsQuestion('Eval key', 'fixture-agent')
        ->expectsQuestion('Eval label', 'Fixture Agent')
        ->expectsQuestion('Where should the test data come from?', 'braintrust_dataset')
        ->assertFailed();

    expect(File::exists(base_path('database/eval-datasets/fixture-agent.json')))->toBeFalse();
});

it('surfaces exceptions raised while building the dataset as a soft error', function (): void {
    config()->set('ai-companion.braintrust.api_url', 'https://api.braintrust.dev');
    config()->set('ai-companion.braintrust.api_key', 'test-key');
    config()->set('ai-companion.braintrust.project', 'my-project');

    Http::fake([
        'api.braintrust.dev/v1/project' => Http::response(['id' => 'proj-1']),
        'api.braintrust.dev/btql' => Http::response(['error' => 'boom'], 500),
    ]);

    $this->artisan('ai:scaffold-eval')
        ->expectsQuestion('Which agent is this eval for?', 'FixtureAgent')
        ->expectsQuestion('Which agent is this eval for?', FixtureAgent::class)
        ->expectsQuestion('Eval key', 'fixture-agent')
        ->expectsQuestion('Eval label', 'Fixture Agent')
        ->expectsQuestion('Where should the test data come from?', 'braintrust_logs')
        ->expectsQuestion('How many past interactions should the dataset hold?', '10')
        ->expectsQuestion('Each row always gets the prompt. What else should it keep?', ['expected', 'metadata'])
        ->assertFailed();

    expect(File::exists(base_path('database/eval-datasets/fixture-agent.json')))->toBeFalse();
});

it('errors when the source returns no rows', function (): void {
    AiResponseLog::query()->delete();

    $this->artisan('ai:scaffold-eval')
        ->expectsQuestion('Which agent is this eval for?', 'FixtureAgent')
        ->expectsQuestion('Which agent is this eval for?', FixtureAgent::class)
        ->expectsQuestion('Eval key', 'fixture-agent')
        ->expectsQuestion('Eval label', 'Fixture Agent')
        ->expectsQuestion('Where should the test data come from?', 'response_logs')
        ->expectsQuestion('How many past interactions should the dataset hold?', '50')
        ->expectsQuestion('Each row always gets the prompt. What else should it keep?', ['expected', 'metadata'])
        ->assertFailed();

    expect(File::exists(base_path('database/eval-datasets/fixture-agent.json')))->toBeFalse();
});

it('declines to overwrite an existing dataset file when not confirmed', function (): void {
    AiResponseLog::create([
        'agent' => FixtureAgent::class,
        'prompt' => 'Plan pages for acme.com',
        'response' => ['text' => 'the plan'],
        'status' => 'success',
    ]);

    File::ensureDirectoryExists(base_path('database/eval-datasets'));
    File::put(base_path('database/eval-datasets/fixture-agent.json'), json_encode(['existing' => true]));

    $this->artisan('ai:scaffold-eval')
        ->expectsQuestion('Which agent is this eval for?', 'FixtureAgent')
        ->expectsQuestion('Which agent is this eval for?', FixtureAgent::class)
        ->expectsQuestion('Eval key', 'fixture-agent')
        ->expectsQuestion('Eval label', 'Fixture Agent')
        ->expectsQuestion('Where should the test data come from?', 'response_logs')
        ->expectsQuestion('How many past interactions should the dataset hold?', '50')
        ->expectsQuestion('Each row always gets the prompt. What else should it keep?', ['expected', 'metadata'])
        ->expectsConfirmation('Overwrite existing database/eval-datasets/fixture-agent.json?', 'no')
        ->assertFailed();

    expect(File::json(base_path('database/eval-datasets/fixture-agent.json')))->toBe(['existing' => true]);
});

it('declines to overwrite an existing eval target when not confirmed', function (): void {
    File::ensureDirectoryExists(app_path('Ai/Eval/Targets'));
    File::put(app_path('Ai/Eval/Targets/FixtureAgentEvalTarget.php'), '<?php // existing target');

    $this->artisan('ai:scaffold-eval')
        ->expectsQuestion('Which agent is this eval for?', 'FixtureAgent')
        ->expectsQuestion('Which agent is this eval for?', FixtureAgent::class)
        ->expectsQuestion('Eval key', 'fixture-agent')
        ->expectsQuestion('Eval label', 'Fixture Agent')
        ->expectsQuestion('Where should the test data come from?', 'skip')
        ->expectsQuestion('Which built-in scorers should judge the answers?', [])
        ->expectsQuestion('Custom scorer names (comma-separated, blank for none)', '')
        ->expectsConfirmation('Overwrite existing FixtureAgentEvalTarget?', 'no')
        ->assertFailed();

    expect(File::get(app_path('Ai/Eval/Targets/FixtureAgentEvalTarget.php')))->toBe('<?php // existing target');
});

it('scaffolds match, range, and tool_routing scorer entries', function (): void {
    $this->artisan('ai:scaffold-eval')
        ->expectsQuestion('Which agent is this eval for?', 'FixtureAgent')
        ->expectsQuestion('Which agent is this eval for?', FixtureAgent::class)
        ->expectsQuestion('Eval key', 'fixture-agent')
        ->expectsQuestion('Eval label', 'Fixture Agent')
        ->expectsQuestion('Where should the test data come from?', 'skip')
        ->expectsQuestion('Which built-in scorers should judge the answers?', ['match', 'range', 'tool_routing'])
        ->expectsQuestion('Custom scorer names (comma-separated, blank for none)', '')
        ->assertSuccessful();

    $target = File::get(app_path('Ai/Eval/Targets/FixtureAgentEvalTarget.php'));
    expect($target)->toContain("new MatchScorer(name: 'match', field: 'text', expected: 'expected')")
        ->and($target)->toContain("new RangeScorer(name: 'length', field: 'text', min: 1, max: 500)")
        ->and($target)->toContain('new ToolRoutingScorer');
});

it('scaffolds js scorers and wires them into the target', function (): void {
    $this->artisan('ai:scaffold-eval')
        ->expectsQuestion('Which agent is this eval for?', 'FixtureAgent')
        ->expectsQuestion('Which agent is this eval for?', FixtureAgent::class)
        ->expectsQuestion('Eval key', 'fixture-agent')
        ->expectsQuestion('Eval label', 'Fixture Agent')
        ->expectsQuestion('Where should the test data come from?', 'skip')
        ->expectsQuestion('Which built-in scorers should judge the answers?', [])
        ->expectsQuestion('Custom scorer names (comma-separated, blank for none)', 'No Hallucinated Urls, no-hallucinated-urls, 123')
        ->assertSuccessful();

    // Deduped after slugging; numeric-only names are skipped like invalid PHP names.
    expect(File::exists(base_path('resources/ai/scorers/no-hallucinated-urls.js')))->toBeTrue()
        ->and(File::get(base_path('resources/ai/scorers/no-hallucinated-urls.js')))->toContain('async function handler')
        ->and(File::exists(base_path('resources/ai/scorers/123.js')))->toBeFalse();

    $target = File::get(app_path('Ai/Eval/Targets/FixtureAgentEvalTarget.php'));
    expect($target)->toContain("new JsScorer(base_path('resources/ai/scorers/no-hallucinated-urls.js'))")
        ->and(substr_count($target, 'new JsScorer'))->toBe(1)
        ->and($target)->toContain('use AgentSoftware\LaravelAiCompanion\Eval\Js\JsScorer;');
});

it('scaffolds tool_usage scorer entries with and without a pattern', function (): void {
    $this->artisan('ai:scaffold-eval')
        ->expectsQuestion('Which agent is this eval for?', 'FixtureAgent')
        ->expectsQuestion('Which agent is this eval for?', FixtureAgent::class)
        ->expectsQuestion('Eval key', 'fixture-agent')
        ->expectsQuestion('Eval label', 'Fixture Agent')
        ->expectsQuestion('Where should the test data come from?', 'skip')
        ->expectsQuestion('Which built-in scorers should judge the answers?', ['tool_usage'])
        ->expectsQuestion('Tool name pattern (wildcard, blank = any tool)', 'Write*')
        ->expectsQuestion('Custom scorer names (comma-separated, blank for none)', '')
        ->assertSuccessful();

    expect(File::get(app_path('Ai/Eval/Targets/FixtureAgentEvalTarget.php')))
        ->toContain("new ToolUsageScorer(pattern: 'Write*')");

    File::deleteDirectory(app_path('Ai'));

    $this->artisan('ai:scaffold-eval')
        ->expectsQuestion('Which agent is this eval for?', 'FixtureAgent')
        ->expectsQuestion('Which agent is this eval for?', FixtureAgent::class)
        ->expectsQuestion('Eval key', 'fixture-agent')
        ->expectsQuestion('Eval label', 'Fixture Agent')
        ->expectsQuestion('Where should the test data come from?', 'skip')
        ->expectsQuestion('Which built-in scorers should judge the answers?', ['tool_usage'])
        ->expectsQuestion('Tool name pattern (wildcard, blank = any tool)', '')
        ->expectsQuestion('Custom scorer names (comma-separated, blank for none)', '')
        ->assertSuccessful();

    expect(File::get(app_path('Ai/Eval/Targets/FixtureAgentEvalTarget.php')))
        ->toContain('new ToolUsageScorer,');
});

it('reuses an existing js scorer file when the overwrite confirm is declined', function (): void {
    $scorerPath = base_path('resources/ai/scorers/no-hallucinated-urls.js');
    File::ensureDirectoryExists(dirname($scorerPath));
    File::put($scorerPath, '// existing js scorer');

    $this->artisan('ai:scaffold-eval')
        ->expectsQuestion('Which agent is this eval for?', 'FixtureAgent')
        ->expectsQuestion('Which agent is this eval for?', FixtureAgent::class)
        ->expectsQuestion('Eval key', 'fixture-agent')
        ->expectsQuestion('Eval label', 'Fixture Agent')
        ->expectsQuestion('Where should the test data come from?', 'skip')
        ->expectsQuestion('Which built-in scorers should judge the answers?', [])
        ->expectsQuestion('Custom scorer names (comma-separated, blank for none)', 'no-hallucinated-urls')
        ->expectsConfirmation('Overwrite existing no-hallucinated-urls.js?', 'no')
        ->assertSuccessful();

    expect(File::get($scorerPath))->toBe('// existing js scorer');

    $target = File::get(app_path('Ai/Eval/Targets/FixtureAgentEvalTarget.php'));
    expect($target)->toContain("new JsScorer(base_path('resources/ai/scorers/no-hallucinated-urls.js'))");
});
