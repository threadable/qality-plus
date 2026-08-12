<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Threadable\QalityPlus\Publisher\BranchWorkItemResolver;
use Threadable\QalityPlus\Publisher\CreateTestCasesService;
use Threadable\QalityPlus\Publisher\GitBranchResolver;
use Threadable\QalityPlus\Publisher\JiraClient;
use Threadable\QalityPlus\Publisher\JiraTestCaseResolver;
use Threadable\QalityPlus\Publisher\JsonlResultReader;
use Threadable\QalityPlus\Publisher\PublisherException;
use Threadable\QalityPlus\Publisher\QalityClient;

final class CreateQalityTestCasesCommand extends Command
{
    protected $signature = 'qality:create-test-cases
        {path? : A JSONL result file or directory; defaults to storage/qality}
        {--branch= : Git branch name; otherwise the current branch is detected}
        {--work-item= : Jira work-item key; skips branch detection and parsing}
        {--mapping-file= : JSON mapping file; defaults to .qality-test-map.json}
        {--dry-run : Validate and summarize without calling QAlity or Jira}';

    protected $description = 'Create missing QAlity test cases and link them to the branch work item';

    public function handle(QalityClient $qality, JiraClient $jira): int
    {
        $workItemKey = null;
        $stage = 'initializing';

        try {
            $workItem = $this->option('work-item');
            $workItemKey = is_string($workItem) && trim($workItem) !== ''
                ? trim($workItem)
                : $this->workItemFromBranch();
            $stage = 'reading test results';
            $records = (new JsonlResultReader((int) config('qality.results.schema_version', 1)))
                ->readPath($this->resultsPath());
            $mappingFile = $this->mappingFile();
            $linking = config('qality.publisher.linking', []);
            $service = new CreateTestCasesService($qality, $jira, [
                'project_id' => config('qality.qality.project_id'),
                'link_type' => is_array($linking) ? $linking['type'] ?? null : null,
                'link_direction' => is_array($linking) ? $linking['direction'] ?? 'test_to_requirement' : 'test_to_requirement',
                'created_test_label' => config('qality.jira.created_test_label'),
            ],
                new JiraTestCaseResolver(
                    $jira,
                    is_string(config('qality.jira.project_key')) ? config('qality.jira.project_key') : null,
                    (string) config('qality.jira.test_issue_type', 'QAlity Test'),
                ),
            );
            $stage = 'creating and linking test cases';
            $summary = $service->create(
                $records,
                $workItemKey,
                $mappingFile,
                (bool) $this->option('dry-run'),
            );
        } catch (PublisherException $exception) {
            Log::error('qality-plus create test cases command failed', [
                'stage' => $stage,
                'work_item' => $workItemKey,
                'dry_run' => (bool) $this->option('dry-run'),
                'exception' => $exception,
            ]);
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

    private function workItemFromBranch(): string
    {
        $branch = is_string($this->option('branch')) && trim($this->option('branch')) !== ''
            ? $this->option('branch')
            : (new GitBranchResolver)->current();

        return (new BranchWorkItemResolver((string) config('qality.create.branch_pattern')))
            ->resolve($branch);
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

    private function resultsPath(): string
    {
        $path = config('qality.results.directory', storage_path('qality'));

        return is_string($path) && trim($path) !== '' ? $path : storage_path('qality');
    }
}
