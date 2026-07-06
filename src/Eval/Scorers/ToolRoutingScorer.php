<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Scorers;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\RequiresExpected;
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\Scorer;
use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\Score;
use Illuminate\Support\Str;

/**
 * Measures whether a tool-using agent routed a message to the right tool(s) — or
 * correctly declined an out-of-scope request without calling anything. Tool
 * names are canonicalised (snake-cased, `_tool` suffix dropped) so the dataset
 * can name a tool either way: `write_copy` or `WriteCopyTool`.
 *
 * Reads `tool_calls` (the names the agent called) and, per row, either
 * `expects_tool` (the expected name or names) or `expects_decline` (true) from
 * the subject input. Pass `declinePhrase` to surface — without scoring — whether
 * a declined reply used the app's standard wording.
 */
final class ToolRoutingScorer implements RequiresExpected, Scorer
{
    public function __construct(
        private string $name = 'routing',
        private ?string $declinePhrase = null,
    ) {}

    public function score(EvalSubject $subject): Score
    {
        $called = $this->canonicalise($subject->input['tool_calls'] ?? []);

        if ((bool) ($subject->input['expects_decline'] ?? false)) {
            return $this->scoreDecline($called, (string) ($subject->input['text'] ?? ''));
        }

        $expected = $this->canonicalise($subject->input['expects_tool'] ?? []);

        if ($expected === []) {
            return new Score($this->name, 1.0, ['note' => 'no expected tool set', 'called' => $called]);
        }

        $matched = array_values(array_intersect($expected, $called));

        // Jaccard: a missing expected tool OR an extra unexpected call both drop
        // the score, so calling the right tool plus a wrong one is not a perfect
        // route.
        $union = count(array_unique([...$expected, ...$called]));

        return new Score($this->name, count($matched) / $union, [
            'expected' => $expected,
            'called' => $called,
            'matched' => $matched,
            'missing' => array_values(array_diff($expected, $called)),
            'unexpected' => array_values(array_diff($called, $expected)),
        ]);
    }

    /**
     * @param  array<int, string>  $called
     */
    private function scoreDecline(array $called, string $reply): Score
    {
        $metadata = ['expected' => 'decline', 'called' => $called];

        if ($this->declinePhrase !== null) {
            $metadata['standard_wording'] = Str::contains($reply, $this->declinePhrase, ignoreCase: true);
        }

        return new Score($this->name, $called === [] ? 1.0 : 0.0, $metadata);
    }

    /**
     * @return array<int, string>
     */
    private function canonicalise(mixed $names): array
    {
        return collect(is_array($names) ? $names : [$names])
            ->filter(fn (mixed $name): bool => is_string($name))
            ->map(fn (string $name): string => Str::of($name)->snake()->replaceEnd('_tool', '')->toString())
            ->unique()
            ->values()
            ->all();
    }
}
