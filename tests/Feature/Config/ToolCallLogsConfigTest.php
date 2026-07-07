<?php

declare(strict_types=1);

it('defaults tool call logging to disabled', function () {
    expect(config('ai-companion.tool_call_logs.enabled'))->toBeFalse();
});
