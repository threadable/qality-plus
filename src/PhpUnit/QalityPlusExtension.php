<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\PhpUnit;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

final class QalityPlusExtension implements Extension
{
    public function bootstrap(
        Configuration $configuration,
        Facade $facade,
        ParameterCollection $parameters,
    ): void {
        $directory = $parameters->has('directory')
            ? $parameters->get('directory')
            : (getenv('QALITY_RESULTS_DIRECTORY') ?: getcwd().DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'qality');
        $schemaVersion = $parameters->has('schema_version')
            ? (int) $parameters->get('schema_version')
            : 1;
        $runId = $parameters->has('run_id') ? $parameters->get('run_id') : '';
        $mappingFile = $parameters->has('mapping_file') ? $parameters->get('mapping_file') : null;

        $collector = new TestResultCollector(
            new ResultWriter($directory, $schemaVersion, $runId),
            new TestMetadataResolver($mappingFile),
        );

        $facade->registerSubscribers(
            new PreparationStartedResultSubscriber($collector),
            new PassedResultSubscriber($collector),
            new FailedResultSubscriber($collector),
            new ErroredResultSubscriber($collector),
            new SkippedResultSubscriber($collector),
            new IncompleteResultSubscriber($collector),
            new RiskyResultSubscriber($collector),
            new FinishedResultSubscriber($collector),
        );
    }
}
