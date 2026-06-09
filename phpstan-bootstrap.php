<?php

declare(strict_types=1);

// Register the ai-companion view namespace so that PHPStan / Larastan can
// resolve view-string literals like 'ai-companion::index' during static analysis.
app('view')->addNamespace('ai-companion', __DIR__.'/resources/views/ai-companion');
