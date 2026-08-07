<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Console;

use Illuminate\Console\Command;
use Threadable\QalityPlus\Publisher\BranchWorkItemResolver;
use Threadable\QalityPlus\Publisher\CreateTestCasesService;
use Threadable\QalityPlus\Publisher\GitBranchResolver;
use Threadable\QalityPlus\Publisher\JiraClient;
use Threadable\QalityPlus\Publisher\JsonlResultReader;
use Threadable\QalityPlus\Publisher\PublisherException;
use Threadable\QalityPlus\Publisher\QalityClient;

final class CreateQalityTestCasesCommand extends Command
{
    protected $signature = 'qality:create-test-cases
        {path : A JSONL result file or a directory containing versioned QAlity JSONL files}
        {--branch= : Git branch name; otherwise the current branch is detected}
        {--mapping-file= : JSON mapping file; defaults to .qality-test-map.json}
        {--dry-run : Validate and summarize without calling QAlity or Jira}';

    protected $description = 'Create missing QAlity test cases and link them to the branch work item';

    public function handle(QalityClient $qality, JiraClient $jira): int
    {
        try {
            $branch = is_string($this->option('branch')) && trim($this->option('branch')) !== ''
                ? $this->option('branch')
                : (new GitBranchResolver)->current();
            $workItemKey = (new BranchWorkItemResolver((string) config('qality.create.branch_pattern')))
                ->resolve($branch);
            $records = (new JsonlResultReader((int) config('qality.results.schema_version', 1)))
                ->readPath((string) $this->argument('path'));
            $mappingFile = $this->mappingFile();
            $linking = config('qality.publisher.linking', []);
            $service = new CreateTestCasesService($qality, $jira, [
                'project_id' => config('qality.qality.project_id'),
                'link_type' => is_array($linking) ? $linking['type'] ?? null : null,
                'link_direction' => is_array($linking) ? $linking['direction'] ?? 'test_to_requirement' : 'test_to_requirement',
            ]);
            $summary = $service->create(
                $records,
                $workItemKey,
                $mappingFile,
                (bool) $this->option('dry-run'),
            );
        } catch (PublisherException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%s %d eligible test case(s) for %s; %d created, %d linked, %d skipped.',
            $summary->dryRun ? 'Validated' : 'Processed',
            $summary->eligible,
            $summary->workItemKey,
            $summary->created,
            $summary->linked,
            $summary->skipped,
        ));

        return self::SUCCESS;
    }

    private function mappingFile(): string
    {
        $configured = $this->option('mapping-file');
        $path = is_string($configured) && trim($configured) !== ''
            ? $configured
            : (string) config('qality.create.mapping_file', '.qality-test-map.json');

        if ($path === '' || $path[0] === DIRECTORY_SEPARATOR) {
            return $path;
        }

        return function_exists('base_path') ? base_path($path) : getcwd().DIRECTORY_SEPARATOR.$path;
    }
}
