<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval;

/**
 * Git metadata for an experiment run, so a backend can auto-select the previous
 * run on the same branch as the comparison baseline. Fields are null outside a
 * git repository.
 */
final readonly class RepoInfo
{
    public function __construct(
        public ?string $branch = null,
        public ?string $commit = null,
        public ?string $commitMessage = null,
        public ?bool $dirty = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'branch' => $this->branch,
            'commit' => $this->commit,
            'commit_message' => $this->commitMessage,
            'dirty' => $this->dirty,
        ], fn (mixed $value): bool => $value !== null);
    }
}
