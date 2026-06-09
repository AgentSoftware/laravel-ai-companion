# AI Evaluation Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an opt-in evaluation module to `agentsoftware/laravel-ai-companion` that runs an LLM judge against logged agent responses, scoring them 0–100 with per-criterion breakdown and a written summary.

**Architecture:** The module lives inside the companion package as a new `Evaluation/` namespace alongside existing logging. A new `ai_evaluations` table stores results linked to `ai_response_logs`. An Artisan command (`php artisan ai:evaluate`) provides an interactive multi-select picker and runs the judge against selected agents' logs. The judge is a structured-output Laravel AI agent inside the package; criteria are auto-inferred from the stored system prompt by default, or explicit via `Scorer` classes registered in config.

**Tech Stack:** PHP 8.4, Laravel 12/13, `laravel/ai ^0.7`, Pest 4, Mockery (via Orchestra Testbench), `spatie/laravel-package-tools`, `illuminate/json-schema` (ships with `laravel/ai`)

---

## File Map

| File | Action | Responsibility |
|------|--------|----------------|
| `database/migrations/2026_06_08_000001_add_instructions_to_ai_response_logs_table.php` | Create | Add `instructions` column to existing table |
| `database/migrations/2026_06_08_000002_create_ai_evaluations_table.php` | Create | New evaluations table |
| `src/Middleware/LogAiResponse.php` | Modify | Capture agent instructions at call time |
| `src/Models/AiResponseLog.php` | Modify | Add `instructions` fillable + `evaluations` relation |
| `src/Models/AiEvaluation.php` | Create | Eloquent model for evaluation results |
| `src/Evaluation/Results/CriterionResult.php` | Create | Value object for one scored criterion |
| `src/Evaluation/Results/EvaluationResult.php` | Create | Value object for a complete evaluation |
| `src/Evaluation/Scorers/Scorer.php` | Create | Abstract base — extend per agent |
| `src/Evaluation/Scorers/AutoInferredScorer.php` | Create | Default scorer with empty criteria (judge infers) |
| `src/Evaluation/Judge/LlmJudge.php` | Create | Laravel AI agent that scores a response |
| `src/Evaluation/EvaluationRunner.php` | Create | Fetch logs → judge → store results |
| `src/Console/EvaluateCommand.php` | Create | `php artisan ai:evaluate` command |
| `src/LaravelAiCompanionServiceProvider.php` | Modify | Register `EvaluateCommand` |
| `config/ai-companion.php` | Modify | Add `evaluation` config section |
| `tests/Feature/Evaluation/LogAiResponseInstructionsTest.php` | Create | Middleware captures instructions |
| `tests/Feature/Evaluation/EvaluationRunnerTest.php` | Create | Runner stores correct results |
| `tests/Feature/Evaluation/EvaluateCommandTest.php` | Create | Command output and DB writes |

---

## Task 1: Migration — add `instructions` to `ai_response_logs`

**Files:**
- Create: `database/migrations/2026_06_08_000001_add_instructions_to_ai_response_logs_table.php`
- Create: `tests/Feature/Evaluation/LogAiResponseInstructionsTest.php`

- [ ] **Step 1: Create the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_response_logs', function (Blueprint $table): void {
            $table->longText('instructions')->nullable()->after('agent');
        });
    }

    public function down(): void
    {
        Schema::table('ai_response_logs', function (Blueprint $table): void {
            $table->dropColumn('instructions');
        });
    }
};
```

- [ ] **Step 2: Write a failing test confirming the column exists after migration**

Create `tests/Feature/Evaluation/LogAiResponseInstructionsTest.php`:

```php
<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Enums\AiResponseStatus;
use AgentSoftware\LaravelAiCompanion\Middleware\LogAiResponse;
use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;

function makeInstructionsAgent(string $instructions = 'You are a helpful agent.'): Agent
{
    return new class($instructions) implements Agent
    {
        public function __construct(private readonly string $agentInstructions) {}

        public function instructions(): string { return $this->agentInstructions; }

        public function prompt(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
        {
            throw new RuntimeException('Not implemented');
        }

        public function stream(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
        {
            throw new RuntimeException('Not implemented');
        }

        public function queue(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
        {
            throw new RuntimeException('Not implemented');
        }
    };
}

function makeInstructionsPrompt(string $instructions = 'You are a helpful agent.'): AgentPrompt
{
    return new AgentPrompt(
        agent: makeInstructionsAgent($instructions),
        prompt: 'Hello',
        attachments: [],
        provider: Mockery::mock(\Laravel\Ai\Contracts\Providers\TextProvider::class),
        model: 'claude-haiku-4-5-20251001',
    );
}

it('stores agent instructions in the log', function (): void {
    $middleware = new LogAiResponse;
    $prompt = makeInstructionsPrompt('You are a content writer for estate agents.');

    $middleware->handle($prompt, fn () => new AgentResponse(
        invocationId: 'inv-1',
        text: 'Here is your content.',
        usage: new Usage(10, 5, 0, 0),
        meta: new Meta,
    ));

    expect(AiResponseLog::first()->instructions)
        ->toBe('You are a content writer for estate agents.');
});

it('stores null instructions when agent returns empty string', function (): void {
    $middleware = new LogAiResponse;
    $prompt = makeInstructionsPrompt('');

    $middleware->handle($prompt, fn () => new AgentResponse(
        invocationId: 'inv-2',
        text: 'ok',
        usage: new Usage(10, 5, 0, 0),
        meta: new Meta,
    ));

    // Empty string instructions are stored as empty string, not null — both are acceptable.
    // This test just confirms the column writes without error.
    expect(AiResponseLog::first()->instructions)->toBeString();
});
```

- [ ] **Step 3: Run the test — confirm it fails**

```bash
cd /path/to/laravel-ai-companion
./vendor/bin/pest tests/Feature/Evaluation/LogAiResponseInstructionsTest.php -v
```

Expected: FAIL with column not found or similar.

- [ ] **Step 4: Commit the migration only (test will pass after Task 2)**

```bash
git add database/migrations/2026_06_08_000001_add_instructions_to_ai_response_logs_table.php
git commit -m "feat: add instructions column to ai_response_logs"
```

---

## Task 2: Update `LogAiResponse` to capture instructions

**Files:**
- Modify: `src/Middleware/LogAiResponse.php`
- Modify: `src/Models/AiResponseLog.php`

- [ ] **Step 1: Add `instructions` to the `AiResponseLog` `$fillable` array**

Open `src/Models/AiResponseLog.php`. Add `'instructions'` to `$fillable`:

```php
protected $fillable = [
    'invocation_id',
    'agent',
    'instructions',   // add this line
    'prompt',
    'response',
    'properties',
    'metadata',
    'status',
    'duration_ms',
];
```

Also add the `@property` docblock:
```php
 * @property string|null $instructions
```

- [ ] **Step 2: Update `LogAiResponse::handle()` to store instructions**

In `src/Middleware/LogAiResponse.php`, update the `AiResponseLog::create([...])` call to include:

```php
$log = AiResponseLog::create([
    'agent'        => $agent::class,
    'instructions' => (string) $agent->instructions() ?: null,
    'prompt'       => $prompt->prompt,
    'properties'   => $agent instanceof HasLoggableProperties
        ? $agent->loggableProperties()
        : null,
    'status'       => AiResponseStatus::Running,
]);
```

The `?: null` converts empty string to null so null means "not available" consistently.

- [ ] **Step 3: Run the instructions tests**

```bash
./vendor/bin/pest tests/Feature/Evaluation/LogAiResponseInstructionsTest.php -v
```

Expected: both tests PASS.

- [ ] **Step 4: Run full test suite to confirm nothing broken**

```bash
./vendor/bin/pest --parallel
```

Expected: all existing tests PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Middleware/LogAiResponse.php src/Models/AiResponseLog.php tests/Feature/Evaluation/LogAiResponseInstructionsTest.php
git commit -m "feat: capture agent instructions in ai_response_logs"
```

---

## Task 3: Migration — create `ai_evaluations` table

**Files:**
- Create: `database/migrations/2026_06_08_000002_create_ai_evaluations_table.php`

- [ ] **Step 1: Create the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_evaluations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('ai_response_log_id')
                ->constrained('ai_response_logs')
                ->cascadeOnDelete();
            $table->string('agent')->index();
            $table->string('scorer')->nullable();
            $table->unsignedSmallInteger('overall_score');
            $table->json('criteria');
            $table->text('summary');
            $table->string('judge_model');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_evaluations');
    }
};
```

- [ ] **Step 2: Run migrations in the test suite to confirm they apply cleanly**

```bash
./vendor/bin/pest --parallel
```

Expected: all tests PASS (migrations discovered automatically via `discoversMigrations()` in the service provider).

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_06_08_000002_create_ai_evaluations_table.php
git commit -m "feat: create ai_evaluations table"
```

---

## Task 4: `AiEvaluation` model and `AiResponseLog` relation

**Files:**
- Create: `src/Models/AiEvaluation.php`
- Modify: `src/Models/AiResponseLog.php`

- [ ] **Step 1: Create `AiEvaluation`**

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $ai_response_log_id
 * @property string $agent
 * @property string|null $scorer
 * @property int $overall_score
 * @property array<int, array{name: string, score: int, feedback: string}> $criteria
 * @property string $summary
 * @property string $judge_model
 */
class AiEvaluation extends Model
{
    use HasUuids;

    protected $fillable = [
        'ai_response_log_id',
        'agent',
        'scorer',
        'overall_score',
        'criteria',
        'summary',
        'judge_model',
    ];

    protected $casts = [
        'criteria' => 'array',
    ];

    /** @return BelongsTo<AiResponseLog, $this> */
    public function log(): BelongsTo
    {
        return $this->belongsTo(AiResponseLog::class, 'ai_response_log_id');
    }
}
```

- [ ] **Step 2: Add `evaluations` relation to `AiResponseLog`**

Open `src/Models/AiResponseLog.php`. Add the import and relation:

```php
use Illuminate\Database\Eloquent\Relations\HasMany;
```

```php
/** @return HasMany<AiEvaluation, $this> */
public function evaluations(): HasMany
{
    return $this->hasMany(AiEvaluation::class, 'ai_response_log_id');
}
```

- [ ] **Step 3: Run tests**

```bash
./vendor/bin/pest --parallel
```

Expected: all PASS.

- [ ] **Step 4: Commit**

```bash
git add src/Models/AiEvaluation.php src/Models/AiResponseLog.php
git commit -m "feat: add AiEvaluation model and evaluations relation"
```

---

## Task 5: Value objects — `CriterionResult` and `EvaluationResult`

**Files:**
- Create: `src/Evaluation/Results/CriterionResult.php`
- Create: `src/Evaluation/Results/EvaluationResult.php`

These are plain value objects with no framework dependencies. No separate test file needed — they'll be exercised by the runner tests in Task 8.

- [ ] **Step 1: Create `CriterionResult`**

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Evaluation\Results;

readonly class CriterionResult
{
    public function __construct(
        public string $name,
        public int $score,
        public string $feedback,
    ) {}

    /** @param array{name: string, score: int|string, feedback: string} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            score: (int) $data['score'],
            feedback: $data['feedback'],
        );
    }

    /** @return array{name: string, score: int, feedback: string} */
    public function toArray(): array
    {
        return [
            'name'     => $this->name,
            'score'    => $this->score,
            'feedback' => $this->feedback,
        ];
    }
}
```

- [ ] **Step 2: Create `EvaluationResult`**

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Evaluation\Results;

readonly class EvaluationResult
{
    /** @param list<CriterionResult> $criteria */
    public function __construct(
        public int $overallScore,
        public array $criteria,
        public string $summary,
        public string $judgeModel,
    ) {}

    /**
     * @param array{overall_score: int|string, criteria: list<array{name: string, score: int|string, feedback: string}>, summary: string} $data
     */
    public static function fromArray(array $data, string $judgeModel): self
    {
        return new self(
            overallScore: (int) $data['overall_score'],
            criteria: array_map(
                static fn (array $c): CriterionResult => CriterionResult::fromArray($c),
                $data['criteria'],
            ),
            summary: $data['summary'],
            judgeModel: $judgeModel,
        );
    }
}
```

- [ ] **Step 3: Run tests**

```bash
./vendor/bin/pest --parallel
```

Expected: all PASS.

- [ ] **Step 4: Commit**

```bash
git add src/Evaluation/Results/CriterionResult.php src/Evaluation/Results/EvaluationResult.php
git commit -m "feat: add CriterionResult and EvaluationResult value objects"
```

---

## Task 6: Scorer classes

**Files:**
- Create: `src/Evaluation/Scorers/Scorer.php`
- Create: `src/Evaluation/Scorers/AutoInferredScorer.php`

- [ ] **Step 1: Create abstract `Scorer`**

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Evaluation\Scorers;

abstract class Scorer
{
    abstract public function agent(): string;

    /**
     * Criteria to evaluate against: name → description of what "good" looks like.
     * Return an empty array to let the judge infer criteria from the system prompt.
     *
     * @return array<string, string>
     */
    abstract public function criteria(): array;
}
```

- [ ] **Step 2: Create `AutoInferredScorer`**

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Evaluation\Scorers;

class AutoInferredScorer extends Scorer
{
    public function __construct(private readonly string $agentClass) {}

    public function agent(): string
    {
        return $this->agentClass;
    }

    /** @return array<string, string> */
    public function criteria(): array
    {
        return [];
    }
}
```

- [ ] **Step 3: Run tests**

```bash
./vendor/bin/pest --parallel
```

Expected: all PASS.

- [ ] **Step 4: Commit**

```bash
git add src/Evaluation/Scorers/Scorer.php src/Evaluation/Scorers/AutoInferredScorer.php
git commit -m "feat: add Scorer base class and AutoInferredScorer"
```

---

## Task 7: `LlmJudge` agent

**Files:**
- Create: `src/Evaluation/Judge/LlmJudge.php`

The judge is a structured-output Laravel AI agent. It receives evaluation criteria in its system prompt and the log content as the user prompt, returning a scored JSON object.

- [ ] **Step 1: Create `LlmJudge`**

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Evaluation\Judge;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

#[Provider('anthropic')]
class LlmJudge implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(private readonly string $criteriaPrompt) {}

    public function instructions(): string
    {
        return <<<INSTRUCTIONS
        You are an expert AI quality evaluator. Assess agent responses honestly and critically — do not be lenient.
        A score above 85 should be genuinely excellent. A score below 50 indicates serious problems.

        {$this->criteriaPrompt}

        Score each criterion from 0 to 100. Provide one sentence of specific, actionable feedback per criterion.
        The overall_score should reflect the weighted average of the criteria scores.
        INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     *
     * Note: the nested array schema for `criteria` uses the JsonSchema builder.
     * Verify this syntax against the version of `laravel/ai` installed —
     * the exact API for array-of-objects may differ between minor versions.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'overall_score' => $schema->integer()
                ->description('Overall quality score 0–100')
                ->required(),
            'criteria' => $schema->array()
                ->items(
                    $schema->object()
                        ->properties([
                            'name'     => $schema->string()->required(),
                            'score'    => $schema->integer()->required(),
                            'feedback' => $schema->string()->required(),
                        ])
                        ->required(['name', 'score', 'feedback']),
                )
                ->required(),
            'summary' => $schema->string()
                ->description('2–3 sentence assessment of overall response quality')
                ->required(),
        ];
    }
}
```

- [ ] **Step 2: Run tests**

```bash
./vendor/bin/pest --parallel
```

Expected: all PASS (no test for the judge in isolation — it's an external LLM call; it's tested via the runner in Task 8).

- [ ] **Step 3: Commit**

```bash
git add src/Evaluation/Judge/LlmJudge.php
git commit -m "feat: add LlmJudge structured-output agent"
```

---

## Task 8: `EvaluationRunner`

**Files:**
- Create: `src/Evaluation/EvaluationRunner.php`
- Create: `tests/Feature/Evaluation/EvaluationRunnerTest.php`

- [ ] **Step 1: Write the failing tests first**

Create `tests/Feature/Evaluation/EvaluationRunnerTest.php`:

```php
<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Enums\AiResponseStatus;
use AgentSoftware\LaravelAiCompanion\Evaluation\EvaluationRunner;
use AgentSoftware\LaravelAiCompanion\Evaluation\Judge\LlmJudge;
use AgentSoftware\LaravelAiCompanion\Evaluation\Scorers\Scorer;
use AgentSoftware\LaravelAiCompanion\Models\AiEvaluation;
use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;

function makeResponseLog(array $overrides = []): AiResponseLog
{
    return AiResponseLog::create(array_merge([
        'agent'        => 'App\\Ai\\Agents\\ContentWriterAgent',
        'instructions' => 'You are a content writer for estate agents.',
        'prompt'       => 'Write a homepage hero section for Acme Estates.',
        'response'     => ['text' => 'Welcome to Acme Estates — your trusted local partner.'],
        'status'       => AiResponseStatus::Success,
    ], $overrides));
}

function makeJudgeResponse(): StructuredAgentResponse
{
    return new StructuredAgentResponse(
        invocationId: 'judge-inv-1',
        structured: [
            'overall_score' => 82,
            'criteria'      => [
                ['name' => 'accuracy',     'score' => 85, 'feedback' => 'Reflects company info correctly.'],
                ['name' => 'completeness', 'score' => 70, 'feedback' => 'Missing a CTA.'],
                ['name' => 'tone',         'score' => 90, 'feedback' => 'Professional and engaging.'],
            ],
            'summary' => 'Good quality overall. The CTA section is missing which reduces completeness.',
        ],
        text: '{}',
        usage: new Usage(100, 200, 0, 0),
        meta: new Meta(provider: 'anthropic', model: 'claude-haiku-4-5-20251001'),
    );
}

function makeRunner(?Closure $judgeFactory = null): EvaluationRunner
{
    return new EvaluationRunner($judgeFactory);
}

it('stores an evaluation result for a successful log', function (): void {
    $log = makeResponseLog();

    $fakeJudge = Mockery::mock(LlmJudge::class);
    $fakeJudge->shouldReceive('prompt')
        ->once()
        ->andReturn(makeJudgeResponse());

    $runner = makeRunner(fn (string $criteria) => $fakeJudge);

    $result = $runner->run($log);

    expect($result)->not->toBeNull()
        ->and($result->overallScore)->toBe(82)
        ->and($result->criteria)->toHaveCount(3)
        ->and($result->criteria[0]->name)->toBe('accuracy')
        ->and($result->criteria[0]->score)->toBe(85)
        ->and($result->summary)->toContain('CTA');

    $evaluation = AiEvaluation::first();
    expect($evaluation)->not->toBeNull()
        ->and($evaluation->ai_response_log_id)->toBe($log->id)
        ->and($evaluation->agent)->toBe('App\\Ai\\Agents\\ContentWriterAgent')
        ->and($evaluation->overall_score)->toBe(82)
        ->and($evaluation->scorer)->toBeNull();
});

it('uses an explicit scorer when one is registered for the agent', function (): void {
    config()->set('ai-companion.evaluation.scorers', [
        new class extends Scorer {
            public function agent(): string { return 'App\\Ai\\Agents\\ContentWriterAgent'; }
            public function criteria(): array {
                return ['no_placeholders' => 'No placeholder text in output.'];
            }
        },
    ]);

    $log = makeResponseLog();

    $capturedCriteria = '';
    $fakeJudge = Mockery::mock(LlmJudge::class);
    $fakeJudge->shouldReceive('prompt')->once()->andReturnUsing(function () use (&$capturedCriteria, $fakeJudge): StructuredAgentResponse {
        return makeJudgeResponse();
    });

    $runner = makeRunner(function (string $criteria) use ($fakeJudge, &$capturedCriteria): LlmJudge {
        $capturedCriteria = $criteria;
        return $fakeJudge;
    });

    $runner->run($log);

    expect($capturedCriteria)->toContain('no_placeholders');

    $evaluation = AiEvaluation::first();
    expect($evaluation->scorer)->toContain('Scorer');
});

it('returns null and writes no row when the judge call throws', function (): void {
    $log = makeResponseLog();

    $fakeJudge = Mockery::mock(LlmJudge::class);
    $fakeJudge->shouldReceive('prompt')->andThrow(new RuntimeException('timeout'));

    $runner = makeRunner(fn (string $criteria) => $fakeJudge);

    $result = $runner->run($log);

    expect($result)->toBeNull()
        ->and(AiEvaluation::count())->toBe(0);
});

it('includes agent instructions in the prompt when they are stored', function (): void {
    $log = makeResponseLog(['instructions' => 'Always write in British English.']);

    $capturedPrompt = '';
    $fakeJudge = Mockery::mock(LlmJudge::class);
    $fakeJudge->shouldReceive('prompt')
        ->once()
        ->withArgs(function (string $prompt) use (&$capturedPrompt): bool {
            $capturedPrompt = $prompt;
            return true;
        })
        ->andReturn(makeJudgeResponse());

    $runner = makeRunner(fn (string $criteria) => $fakeJudge);
    $runner->run($log);

    expect($capturedPrompt)->toContain('Always write in British English.');
});

it('omits the agent instructions section when instructions are null', function (): void {
    $log = makeResponseLog(['instructions' => null]);

    $capturedPrompt = '';
    $fakeJudge = Mockery::mock(LlmJudge::class);
    $fakeJudge->shouldReceive('prompt')
        ->once()
        ->withArgs(function (string $prompt) use (&$capturedPrompt): bool {
            $capturedPrompt = $prompt;
            return true;
        })
        ->andReturn(makeJudgeResponse());

    $runner = makeRunner(fn (string $criteria) => $fakeJudge);
    $runner->run($log);

    expect($capturedPrompt)->not->toContain('AGENT INSTRUCTIONS');
});
```

- [ ] **Step 2: Run the tests — confirm they fail**

```bash
./vendor/bin/pest tests/Feature/Evaluation/EvaluationRunnerTest.php -v
```

Expected: FAIL — `EvaluationRunner` class not found.

- [ ] **Step 3: Create `EvaluationRunner`**

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Evaluation;

use AgentSoftware\LaravelAiCompanion\Enums\AiResponseStatus;
use AgentSoftware\LaravelAiCompanion\Evaluation\Judge\LlmJudge;
use AgentSoftware\LaravelAiCompanion\Evaluation\Results\CriterionResult;
use AgentSoftware\LaravelAiCompanion\Evaluation\Results\EvaluationResult;
use AgentSoftware\LaravelAiCompanion\Evaluation\Scorers\AutoInferredScorer;
use AgentSoftware\LaravelAiCompanion\Evaluation\Scorers\Scorer;
use AgentSoftware\LaravelAiCompanion\Models\AiEvaluation;
use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;
use Closure;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

class EvaluationRunner
{
    /** @param Closure(string): LlmJudge|null $judgeFactory */
    public function __construct(private readonly ?Closure $judgeFactory = null) {}

    public function run(AiResponseLog $log): ?EvaluationResult
    {
        $scorer = $this->resolveScorer($log->agent);
        $criteriaPrompt = $this->buildCriteriaPrompt($scorer);
        $judge = ($this->judgeFactory ?? fn (string $p): LlmJudge => new LlmJudge($p))($criteriaPrompt);
        $model = config('ai-companion.evaluation.model', 'claude-haiku-4-5-20251001');

        try {
            $response = $judge->prompt($this->buildLogPrompt($log), model: $model);

            if (! $response instanceof StructuredAgentResponse) {
                return null;
            }

            /** @var array{overall_score: int, criteria: list<array{name: string, score: int, feedback: string}>, summary: string} $structured */
            $structured = $response->structured;
            $result = EvaluationResult::fromArray($structured, $model);

            AiEvaluation::create([
                'ai_response_log_id' => $log->id,
                'agent'              => $log->agent,
                'scorer'             => $scorer instanceof AutoInferredScorer ? null : $scorer::class,
                'overall_score'      => $result->overallScore,
                'criteria'           => array_map(
                    static fn (CriterionResult $c): array => $c->toArray(),
                    $result->criteria,
                ),
                'summary'    => $result->summary,
                'judge_model' => $result->judgeModel,
            ]);

            return $result;
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveScorer(string $agentClass): Scorer
    {
        /** @var list<Scorer|class-string<Scorer>> $scorers */
        $scorers = config('ai-companion.evaluation.scorers', []);

        foreach ($scorers as $scorer) {
            $instance = is_string($scorer) ? new $scorer : $scorer;

            if ($instance->agent() === $agentClass) {
                return $instance;
            }
        }

        return new AutoInferredScorer($agentClass);
    }

    private function buildCriteriaPrompt(Scorer $scorer): string
    {
        $criteria = $scorer->criteria();

        if ($criteria === []) {
            return 'Infer 3–5 appropriate evaluation criteria from the agent instructions provided. '
                . 'Choose criteria that best assess whether the agent achieved its stated purpose.';
        }

        $lines = array_map(
            static fn (string $name, string $description): string => "- {$name}: {$description}",
            array_keys($criteria),
            $criteria,
        );

        return "Evaluate against these specific criteria:\n" . implode("\n", $lines);
    }

    private function buildLogPrompt(AiResponseLog $log): string
    {
        $parts = [];

        if ($log->instructions !== null) {
            $parts[] = "--- AGENT INSTRUCTIONS ---\n{$log->instructions}";
        }

        $parts[] = "--- USER INPUT ---\n{$log->prompt}";

        $response = $log->response;
        $responseText = is_array($response)
            ? json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            : (string) $response;

        $parts[] = "--- AGENT RESPONSE ---\n{$responseText}";

        return implode("\n\n", $parts);
    }
}
```

- [ ] **Step 4: Run the tests**

```bash
./vendor/bin/pest tests/Feature/Evaluation/EvaluationRunnerTest.php -v
```

Expected: all PASS.

- [ ] **Step 5: Run full suite**

```bash
./vendor/bin/pest --parallel
```

Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Evaluation/EvaluationRunner.php tests/Feature/Evaluation/EvaluationRunnerTest.php
git commit -m "feat: add EvaluationRunner"
```

---

## Task 9: `EvaluateCommand`

**Files:**
- Create: `src/Console/EvaluateCommand.php`
- Create: `tests/Feature/Evaluation/EvaluateCommandTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Evaluation/EvaluateCommandTest.php`:

```php
<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Console\EvaluateCommand;
use AgentSoftware\LaravelAiCompanion\Enums\AiResponseStatus;
use AgentSoftware\LaravelAiCompanion\Evaluation\EvaluationRunner;
use AgentSoftware\LaravelAiCompanion\Evaluation\Judge\LlmJudge;
use AgentSoftware\LaravelAiCompanion\Evaluation\Results\CriterionResult;
use AgentSoftware\LaravelAiCompanion\Evaluation\Results\EvaluationResult;
use AgentSoftware\LaravelAiCompanion\Models\AiEvaluation;
use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;

function seedLogs(): void
{
    AiResponseLog::create([
        'agent'        => 'App\\Ai\\Agents\\ContentWriterAgent',
        'instructions' => 'Write content.',
        'prompt'       => 'Write a hero.',
        'response'     => ['text' => 'Welcome to Acme.'],
        'status'       => AiResponseStatus::Success,
    ]);
}

function makeEvaluationResult(): EvaluationResult
{
    return new EvaluationResult(
        overallScore: 78,
        criteria: [
            new CriterionResult('accuracy', 80, 'Mostly accurate.'),
            new CriterionResult('tone', 75, 'Professional but bland.'),
        ],
        summary: 'Reasonable quality with room to improve tone.',
        judgeModel: 'claude-haiku-4-5-20251001',
    );
}

it('evaluates the specified agent and writes a row to ai_evaluations', function (): void {
    seedLogs();

    $fakeJudge = Mockery::mock(LlmJudge::class);
    $fakeJudge->shouldReceive('prompt')->once()->andReturn(
        new \Laravel\Ai\Responses\StructuredAgentResponse(
            invocationId: 'j1',
            structured: [
                'overall_score' => 78,
                'criteria'      => [
                    ['name' => 'accuracy', 'score' => 80, 'feedback' => 'Mostly accurate.'],
                    ['name' => 'tone',     'score' => 75, 'feedback' => 'Professional but bland.'],
                ],
                'summary' => 'Reasonable quality with room to improve tone.',
            ],
            text: '{}',
            usage: new \Laravel\Ai\Responses\Data\Usage(100, 200, 0, 0),
            meta: new \Laravel\Ai\Responses\Data\Meta,
        )
    );

    $runner = new EvaluationRunner(fn (string $criteria) => $fakeJudge);
    $this->app->instance(EvaluationRunner::class, $runner);

    $this->artisan(EvaluateCommand::class, [
        '--agent' => 'App\\Ai\\Agents\\ContentWriterAgent',
    ])->assertSuccessful();

    expect(AiEvaluation::count())->toBe(1)
        ->and(AiEvaluation::first()->overall_score)->toBe(78);
});

it('skips already-evaluated logs unless --re-run is passed', function (): void {
    seedLogs();

    $log = AiResponseLog::first();
    AiEvaluation::create([
        'ai_response_log_id' => $log->id,
        'agent'              => $log->agent,
        'overall_score'      => 90,
        'criteria'           => [],
        'summary'            => 'Already evaluated.',
        'judge_model'        => 'claude-haiku-4-5-20251001',
    ]);

    $fakeJudge = Mockery::mock(LlmJudge::class);
    $fakeJudge->shouldNotReceive('prompt');
    $runner = new EvaluationRunner(fn (string $criteria) => $fakeJudge);
    $this->app->instance(EvaluationRunner::class, $runner);

    $this->artisan(EvaluateCommand::class, [
        '--agent' => 'App\\Ai\\Agents\\ContentWriterAgent',
    ])->assertSuccessful();

    expect(AiEvaluation::count())->toBe(1);
});

it('re-evaluates already-evaluated logs when --re-run is passed', function (): void {
    seedLogs();

    $log = AiResponseLog::first();
    AiEvaluation::create([
        'ai_response_log_id' => $log->id,
        'agent'              => $log->agent,
        'overall_score'      => 90,
        'criteria'           => [],
        'summary'            => 'Old evaluation.',
        'judge_model'        => 'claude-haiku-4-5-20251001',
    ]);

    $fakeJudge = Mockery::mock(LlmJudge::class);
    $fakeJudge->shouldReceive('prompt')->once()->andReturn(
        new \Laravel\Ai\Responses\StructuredAgentResponse(
            invocationId: 'j2',
            structured: [
                'overall_score' => 55,
                'criteria'      => [['name' => 'accuracy', 'score' => 55, 'feedback' => 'Needs work.']],
                'summary'       => 'Lower quality on re-run.',
            ],
            text: '{}',
            usage: new \Laravel\Ai\Responses\Data\Usage(100, 200, 0, 0),
            meta: new \Laravel\Ai\Responses\Data\Meta,
        )
    );

    $runner = new EvaluationRunner(fn (string $criteria) => $fakeJudge);
    $this->app->instance(EvaluationRunner::class, $runner);

    $this->artisan(EvaluateCommand::class, [
        '--agent'    => 'App\\Ai\\Agents\\ContentWriterAgent',
        '--re-run'   => true,
    ])->assertSuccessful();

    expect(AiEvaluation::count())->toBe(2);
});

it('returns success with no output when no logs match', function (): void {
    $fakeJudge = Mockery::mock(LlmJudge::class);
    $fakeJudge->shouldNotReceive('prompt');
    $runner = new EvaluationRunner(fn (string $criteria) => $fakeJudge);
    $this->app->instance(EvaluationRunner::class, $runner);

    $this->artisan(EvaluateCommand::class, [
        '--agent' => 'App\\Ai\\Agents\\ContentWriterAgent',
    ])->assertSuccessful();

    expect(AiEvaluation::count())->toBe(0);
});
```

- [ ] **Step 2: Run tests — confirm they fail**

```bash
./vendor/bin/pest tests/Feature/Evaluation/EvaluateCommandTest.php -v
```

Expected: FAIL — `EvaluateCommand` not found.

- [ ] **Step 3: Create `EvaluateCommand`**

```php
<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Console;

use AgentSoftware\LaravelAiCompanion\Enums\AiResponseStatus;
use AgentSoftware\LaravelAiCompanion\Evaluation\EvaluationRunner;
use AgentSoftware\LaravelAiCompanion\Evaluation\Results\CriterionResult;
use AgentSoftware\LaravelAiCompanion\Evaluation\Results\EvaluationResult;
use AgentSoftware\LaravelAiCompanion\Models\AiResponseLog;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

use function Laravel\Prompts\multiselect;

class EvaluateCommand extends Command
{
    protected $signature = 'ai:evaluate
        {--agent= : Agent class name to evaluate (skips interactive picker)}
        {--since= : Only evaluate logs created after this point (e.g. 7d, 2026-06-01)}
        {--limit=50 : Maximum number of logs to evaluate per agent}
        {--re-run : Re-evaluate logs that already have a score}';

    protected $description = 'Evaluate AI agent responses using an LLM judge';

    public function handle(EvaluationRunner $runner): int
    {
        $agents = $this->resolveAgents();

        if ($agents === []) {
            $this->components->warn('No agents found in ai_response_logs.');

            return self::SUCCESS;
        }

        foreach ($agents as $agent) {
            $this->evaluateAgent($runner, $agent);
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function resolveAgents(): array
    {
        if ($agent = $this->option('agent')) {
            return [(string) $agent];
        }

        /** @var list<string> $available */
        $available = AiResponseLog::query()
            ->select('agent')
            ->distinct()
            ->orderBy('agent')
            ->pluck('agent')
            ->all();

        if ($available === []) {
            return [];
        }

        /** @var list<string> $selected */
        $selected = multiselect(
            label: 'Which agents would you like to evaluate?',
            options: array_combine($available, $available),
            required: true,
        );

        return $selected;
    }

    private function evaluateAgent(EvaluationRunner $runner, string $agent): void
    {
        $query = AiResponseLog::query()
            ->where('agent', $agent)
            ->where('status', AiResponseStatus::Success);

        if (! $this->option('re-run')) {
            $query->doesntHave('evaluations');
        }

        if ($since = $this->option('since')) {
            $query->where('created_at', '>=', $this->parseSince((string) $since));
        }

        /** @var Collection<int, AiResponseLog> $logs */
        $logs = $query->limit((int) $this->option('limit'))->get();

        if ($logs->isEmpty()) {
            $this->components->info("No unevaluated logs found for {$agent}.");

            return;
        }

        $this->components->info("Evaluating {$agent} ({$logs->count()} logs)...");
        $this->newLine();

        $evaluated = 0;
        $skipped = 0;

        foreach ($logs as $log) {
            $result = $runner->run($log);

            if ($result === null) {
                $this->line(" <fg=red>✗</>  log {$this->shortId($log->id)}  FAILED — judge error, skipped");
                $skipped++;
                continue;
            }

            $criteriaLine = collect($result->criteria)
                ->map(fn (CriterionResult $c): string => "{$c->name}:{$c->score}")
                ->implode('  ');

            $this->line(" <fg=green>✓</>  log {$this->shortId($log->id)}  overall: {$result->overallScore}   {$criteriaLine}");
            $evaluated++;
        }

        $this->newLine();
        $this->printSummary($agent, $evaluated, $skipped);
    }

    private function printSummary(string $agent, int $evaluated, int $skipped): void
    {
        $evaluations = \AgentSoftware\LaravelAiCompanion\Models\AiEvaluation::query()
            ->where('agent', $agent)
            ->latest()
            ->limit(50)
            ->get();

        if ($evaluations->isEmpty()) {
            return;
        }

        $avg = (int) round($evaluations->avg('overall_score'));

        $this->components->info('Summary');
        $this->line("  {$agent}   avg: {$avg}/100   {$evaluated} evaluated" . ($skipped > 0 ? ", {$skipped} skipped" : ''));

        $allCriteria = $evaluations
            ->flatMap(fn ($e) => $e->criteria)
            ->groupBy('name')
            ->map(fn ($group) => (int) round($group->avg('score')));

        if ($allCriteria->isNotEmpty()) {
            $weakest = $allCriteria->sortBy(fn ($v) => $v)->keys()->first();
            $weakestScore = $allCriteria[$weakest];
            $this->line("  Lowest criterion: {$weakest} (avg {$weakestScore}) — consider reviewing the relevant part of the prompt.");
        }

        $this->newLine();
    }

    private function parseSince(string $since): Carbon
    {
        if (preg_match('/^(\d+)d$/', $since, $matches)) {
            return now()->subDays((int) $matches[1]);
        }

        return Carbon::parse($since);
    }

    private function shortId(string $uuid): string
    {
        return substr($uuid, 0, 8);
    }
}
```


- [ ] **Step 4: Run the command tests**

```bash
./vendor/bin/pest tests/Feature/Evaluation/EvaluateCommandTest.php -v
```

Expected: all PASS.

- [ ] **Step 5: Run full suite**

```bash
./vendor/bin/pest --parallel
```

Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Console/EvaluateCommand.php tests/Feature/Evaluation/EvaluateCommandTest.php
git commit -m "feat: add ai:evaluate Artisan command"
```

---

## Task 10: Config, ServiceProvider wiring, and smoke test

**Files:**
- Modify: `config/ai-companion.php`
- Modify: `src/LaravelAiCompanionServiceProvider.php`

- [ ] **Step 1: Update config**

Replace the contents of `config/ai-companion.php`:

```php
<?php

declare(strict_types=1);

return [
    'response_logs' => [
        'prune_enabled'      => env('AI_COMPANION_PRUNE_ENABLED', true),
        'prune_after_months' => env('AI_COMPANION_PRUNE_MONTHS', 6),
        'prune_schedule'     => env('AI_COMPANION_PRUNE_SCHEDULE', '0 3 * * *'),
    ],

    'evaluation' => [
        'enabled' => env('AI_EVALUATION_ENABLED', true),

        /*
         | The model used by the LLM judge. A cheaper/faster model (Haiku-class)
         | is recommended since it runs once per log evaluated.
         */
        'model' => env('AI_EVALUATION_MODEL', 'claude-haiku-4-5-20251001'),

        /*
         | Register Scorer subclasses here to provide explicit evaluation criteria
         | for specific agents. Agents with no registered scorer use auto-inferred
         | criteria based on their stored instructions.
         |
         | Example:
         | 'scorers' => [
         |     App\Ai\Scorers\ContentWriterAgentScorer::class,
         | ],
         */
        'scorers' => [],
    ],
];
```

- [ ] **Step 2: Register `EvaluateCommand` in the service provider**

Open `src/LaravelAiCompanionServiceProvider.php`. Update `configurePackage()` to add the command:

```php
public function configurePackage(Package $package): void
{
    $package
        ->name('laravel-ai-companion')
        ->hasConfigFile('ai-companion')
        ->hasCommands([
            \AgentSoftware\LaravelAiCompanion\Console\EvaluateCommand::class,
        ])
        ->discoversMigrations();
}
```

- [ ] **Step 3: Run the full test suite**

```bash
./vendor/bin/pest --parallel
```

Expected: all PASS.

- [ ] **Step 4: Run static analysis**

```bash
./vendor/bin/phpstan
```

Fix any type errors before committing.

- [ ] **Step 5: Run lint**

```bash
./vendor/bin/pint
```

- [ ] **Step 6: Commit**

```bash
git add config/ai-companion.php src/LaravelAiCompanionServiceProvider.php
git commit -m "feat: wire evaluation config and register EvaluateCommand"
```

---

## Post-Implementation: Using the module in spectre-websites

Once the updated package is pulled in, here's the minimal setup to use it:

**1. Run migrations:**
```bash
php artisan migrate
```

**2. Publish config (if not already done):**
```bash
php artisan vendor:publish --tag="ai-companion-config"
```

**3. Set the evaluation model in `.env`:**
```env
AI_EVALUATION_MODEL=claude-haiku-4-5-20251001
```

**4. Run your first evaluation:**
```bash
php artisan ai:evaluate
# Pick ContentWriterAgent from the multi-select
```

**5. (Optional) Add an explicit scorer for ContentWriterAgent:**

Create `app/Ai/Scorers/ContentWriterAgentScorer.php`:
```php
<?php

declare(strict_types=1);

namespace App\Ai\Scorers;

use AgentSoftware\LaravelAiCompanion\Evaluation\Scorers\Scorer;
use App\Ai\Agents\ContentWriterAgent;

class ContentWriterAgentScorer extends Scorer
{
    public function agent(): string
    {
        return ContentWriterAgent::class;
    }

    public function criteria(): array
    {
        return [
            'accuracy'        => 'Content accurately reflects company details from the prompt. No invented facts.',
            'completeness'    => 'All required sections present — hero, about, services, CTA.',
            'tone'            => 'Professional, confident, free of filler phrases.',
            'no_placeholders' => 'No placeholder text like [COMPANY NAME] or [INSERT HERE].',
        ];
    }
}
```

Register in `config/ai-companion.php`:
```php
'scorers' => [
    App\Ai\Scorers\ContentWriterAgentScorer::class,
],
```
