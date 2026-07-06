<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Js;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\Scorer;
use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\Score;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

/**
 * A scorer written in JS, Braintrust-convention (`function handler({...})`),
 * versioned in the app and executed locally via Node. OFFLINE BY DESIGN:
 * score() performs zero HTTP - nothing reaches Braintrust until the file is
 * explicitly published with ai:publish-eval.
 */
final readonly class JsScorer implements Scorer
{
    public function __construct(public string $path) {}

    public function name(): string
    {
        return Str::snake(str_replace('-', '_', pathinfo($this->path, PATHINFO_FILENAME)));
    }

    public function code(): string
    {
        $code = @file_get_contents($this->path);

        if ($code === false) {
            throw new RuntimeException("JS scorer file not readable: {$this->path}");
        }

        return $code;
    }

    public function score(EvalSubject $subject): Score
    {
        $payload = json_encode([
            'output' => $subject->output,
            'input' => $subject->input,
            'expected' => $subject->input['expected'] ?? null,
        ], JSON_THROW_ON_ERROR);

        $result = Process::input($payload)
            ->timeout(60)
            ->run(['node', __DIR__.'/scorer-runner.mjs', $this->path]);

        if ($result->failed()) {
            // 127 = command not found: the one failure that isn't the scorer's fault.
            $error = $result->exitCode() === 127
                ? 'Node.js not found on PATH — it is required to run JS scorers locally.'
                : (trim($result->errorOutput()) !== '' ? trim($result->errorOutput()) : 'node exited '.$result->exitCode());

            return new Score($this->name(), 0.0, ['error' => $error]);
        }

        try {
            $decoded = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return new Score($this->name(), 0.0, ['error' => 'Runner printed invalid JSON: '.$exception->getMessage()]);
        }

        $score = is_array($decoded) ? (float) ($decoded['score'] ?? 0.0) : (float) $decoded;
        $metadata = is_array($decoded) && is_array($decoded['metadata'] ?? null) ? $decoded['metadata'] : [];

        return new Score($this->name(), max(0.0, min(1.0, $score)), $metadata);
    }
}
