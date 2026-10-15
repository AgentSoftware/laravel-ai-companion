<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\Scorers\LlmJudgeScorer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Runs one judge call through the real Anthropic gateway against a faked API, and
 * returns the request body it sent. JudgeAgent::fake() can't catch these: the fake
 * receives the model before the gateway resolves the provider/model pair.
 *
 * @return array<string, mixed>
 */
function judgeRequestBody(): array
{
    config()->set('ai.providers.anthropic', ['driver' => 'anthropic', 'key' => 'test-key']);

    Http::fake(['api.anthropic.com/*' => Http::response([
        'id' => 'msg_1',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-test',
        'content' => [['type' => 'text', 'text' => '{"rating":5,"reasoning":"halfway"}']],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ])]);

    (new LlmJudgeScorer(name: 'faithful', rubric: 'rate it', input: 'brief', output: 'name'))->score(new EvalSubject(
        output: ['name' => 'Spring Sale Launch'],
        input: ['brief' => 'Announce the spring sale'],
    ));

    $body = [];
    Http::assertSent(function (Request $request) use (&$body): bool {
        $body = $request->data();

        return str_ends_with($request->url(), '/messages');
    });

    return $body;
}

it('uses the configured judge model when no judge provider is configured', function (): void {
    config()->set('ai-companion.eval.judge.provider', null);
    config()->set('ai-companion.eval.judge.model', 'claude-sonnet-5-5');

    expect(judgeRequestBody()['model'])->toBe('claude-sonnet-5-5');
});

it('defaults to the cheapest Anthropic model when no judge model is configured', function (): void {
    config()->set('ai-companion.eval.judge.provider', null);
    config()->set('ai-companion.eval.judge.model', null);

    expect(judgeRequestBody()['model'])->toBe('claude-haiku-5-5');
});
