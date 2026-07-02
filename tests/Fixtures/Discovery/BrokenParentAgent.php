<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Fixtures\Discovery;

// Extends a class that does not exist anywhere in the autoloader. Referencing
// this class name (e.g. via class_exists or ReflectionClass) triggers PHP's
// autoloader, which throws when it cannot resolve the parent class.
final class BrokenParentAgent extends TotallyMissingParentClassThatDoesNotExist {}
