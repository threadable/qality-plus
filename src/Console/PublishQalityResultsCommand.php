<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Console;

use Illuminate\Console\Command;
use Threadable\QalityPlus\Publisher\JiraClient;
use Threadable\QalityPlus\Publisher\JsonlResultReader;
use Threadable\QalityPlus\Publisher\PublisherException;
use Threadable\QalityPlus\Publisher\QalityClient;
use Threadable\QalityPlus\Publisher\QalityPublisher;

final class PublishQalityResultsCommand extends Command
{
    protected $signature = 'qality:publish
        {path? : A JSONL result file or directory; defaults to storage/qality}
        {--cycle-id= : Existing QAlity test cycle ID; otherwise a new cycle is created}
        {--dry-run : Validate and summarize without calling QAlity or Jira}';

    protected $description = 'Publish PHPUnit JSONL results to QAlity Plus and optional Jira links';

    public function handle(QalityClient $qality, JiraClient $jira): int
    {
        try {
            $records = (new JsonlResultReader((int) config('qality.results.schema_version', 1)))
                ->readPath($this->resultsPath());
            $publisher = new QalityPublisher($qality, $jira, [
                'project_id' => config('qality.qality.project_id'),
                'cycle_id' => config('qality.qality.cycle_id'),
                'cycle_name' => config('qality.qality.cycle_name'),
                'cycle_comment' => config('qality.qality.cycle_comment'),
                'linking' => config('qality.publisher.linking', []),
            ]);
            $summary = $publisher->publish(
                $records,
                is_string($this->option('cycle-id')) ? $this->option('cycle-id') : null,
                (bool) $this->option('dry-run'),
            );
        } catch (PublisherException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%s %d result(s); %d published, %d skipped, %d Jira link(s)%s.',
            $summary->dryRun ? 'Validated' : 'Published',
            $summary->total,
            $summary->published,
            $summary->skipped,
            $summary->linked,
            $summary->cycleId === null ? '' : ' in cycle '.$summary->cycleId,
        ));

        return self::SUCCESS;
    }

    private function resultsPath(): string
    {
        $path = config('qality.results.directory', storage_path('qality'));

        return is_string($path) && trim($path) !== '' ? $path : storage_path('qality');
    }
}
