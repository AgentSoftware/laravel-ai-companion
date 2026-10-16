<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Commands;

use AgentSoftware\LaravelAiCompanion\Eval\LocalFixtureFiles;
use AgentSoftware\LaravelAiCompanion\Eval\Scaffolding\BraintrustApi;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\outro;

/**
 * Publishes a local JSON dataset to a Braintrust dataset in the configured
 * project, uploading every `local_file` fixture as an attachment, so teammates
 * and CI can run `ai:eval --dataset=braintrust:<name>` without the files in git.
 */
class PublishDatasetCommand extends Command
{
    protected $signature = 'ai:publish-dataset
        {path : Local dataset JSON file, relative to the app base path}
        {--name= : Braintrust dataset name (defaults to the file name)}
        {--replace : Replace rows that already exist instead of skipping them}
        {--dry-run : Check the fixture files and count the rows without calling Braintrust}';

    protected $description = 'Publish a local eval dataset and its fixture files to a Braintrust dataset';

    public function handle(BraintrustApi $api): int
    {
        $path = base_path((string) $this->argument('path'));

        if (! File::exists($path)) {
            error("Dataset file [{$this->argument('path')}] does not exist.");

            return self::FAILURE;
        }

        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = collect(File::json($path))->map(fn (array $row): array => ['id' => $this->rowId($row)] + $row);

        if ($rows->isEmpty()) {
            error('Dataset file has no rows.');

            return self::FAILURE;
        }

        $fixtures = new LocalFixtureFiles;
        $name = (string) ($this->option('name') ?: pathinfo($path, PATHINFO_FILENAME));

        try {
            if ($this->option('dry-run')) {
                return $this->dryRun($rows, $fixtures, $name);
            }

            $datasetId = $api->findOrCreateDataset($name);
            $existing = $this->option('replace') ? [] : $api->datasetRowIds($datasetId);
            $new = $rows->reject(fn (array $row): bool => in_array($row['id'], $existing, true))->values();

            if ($new->isEmpty()) {
                outro("Dataset [{$name}] already has all {$rows->count()} row(s) — nothing to publish.");

                return self::SUCCESS;
            }

            $events = $new->map(fn (array $row): array => $this->event($fixtures->replace($row, function (string $file, string $filename, string $contentType) use ($api): array {
                info("Uploading {$filename}");

                return $api->uploadAttachment($filename, $contentType, File::get($file));
            })));

            $api->insertDatasetRows($datasetId, $events->all());
        } catch (Throwable $exception) {
            error('Publish aborted — '.$exception->getMessage());

            return self::FAILURE;
        }

        outro(sprintf('Published %d row(s) to Braintrust dataset "%s" (%d already there).', $events->count(), $name, $rows->count() - $events->count()));

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function dryRun(Collection $rows, LocalFixtureFiles $fixtures, string $name): int
    {
        $files = 0;

        $rows->each(fn (array $row): array => $fixtures->replace($row, function () use (&$files): null {
            $files++;

            return null;
        }));

        outro(sprintf('Dry run: would publish %d row(s) and upload %d file(s) to Braintrust dataset "%s".', $rows->count(), $files, $name));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowId(array $row): string
    {
        return (string) ($row['id'] ?? sha1(json_encode($row, JSON_THROW_ON_ERROR)));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: string, input: array<string, mixed>, expected?: mixed, tags?: array<int, string>}
     */
    private function event(array $row): array
    {
        return [
            'id' => $row['id'],
            'input' => Arr::except($row, ['id', 'expected', 'tags']),
            ...Arr::only($row, ['expected', 'tags']),
        ];
    }
}
