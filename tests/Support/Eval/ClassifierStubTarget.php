<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Support\Eval;

use AgentSoftware\LaravelAiCompanion\Eval\ClassificationCase;
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\ClassifierEvalTarget;
use AgentSoftware\LaravelAiCompanion\Eval\ExpectedAnswer;
use AgentSoftware\LaravelAiCompanion\Eval\ExpectedAnswerTag;
use AgentSoftware\LaravelAiCompanion\Eval\Scorers\BooleanAnswerScorer;
use AgentSoftware\LaravelAiCompanion\Eval\Scorers\ChoiceAnswerScorer;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Files\File;

class ClassifierStubTarget implements ClassifierEvalTarget
{
    public function key(): string
    {
        return 'stub-classifier';
    }

    public function label(): string
    {
        return 'Classifier stub';
    }

    public function defaultDataset(): string
    {
        return 'eval-dataset.json';
    }

    public function scorers(): array
    {
        return [
            new ChoiceAnswerScorer('priority'),
            new BooleanAnswerScorer('gas'),
            new BooleanAnswerScorer('gas', tag: ExpectedAnswerTag::MustCatch),
            new BooleanAnswerScorer('gas', tag: ExpectedAnswerTag::MustPass),
        ];
    }

    public function classification(array $row): ClassificationCase
    {
        $questions = match ($row['decision']) {
            'priority' => ['priority' => new Choice('How urgent is the repair?', ['routine' => null, 'urgent' => null])],
            'hazard' => ['gas' => new Boolean('Is there a gas hazard?')],
        };

        return new ClassificationCase(
            state: $row['state'],
            questions: $questions,
            expected: ExpectedAnswer::fromDataset($row['expected'] ?? []),
            attachments: array_map(
                fn (array|File $attachment): File => $attachment instanceof File ? $attachment : File::fromArray($attachment),
                $row['attachments'] ?? [],
            ),
        );
    }
}
