<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval;

use Illuminate\Http\UploadedFile;
use Laravel\Ai\Contracts\Question;
use Laravel\Ai\Files\File;

final readonly class ClassificationCase
{
    /**
     * @param  string|array<string, mixed>  $state
     * @param  array<string, Question>  $questions
     * @param  array<string, ExpectedAnswer>  $expected  Keyed by question; a question with no expectation is not scored.
     * @param  array<int, File|UploadedFile>  $attachments
     */
    public function __construct(
        public string|array $state,
        public array $questions,
        public array $expected = [],
        public array $attachments = [],
    ) {}
}
