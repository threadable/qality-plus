<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Tests\Feature;

use Symfony\Component\Process\Process;
use Threadable\QalityPlus\Publisher\JsonlResultReader;
use Threadable\QalityPlus\Tests\TestCase;

final class PhpUnitExtensionTest extends TestCase
{
    public function test_the_extension_records_real_phpunit_outcomes_and_metadata(): void
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qality-extension-'.bin2hex(random_bytes(4));
        $configuration = $directory.'.xml';
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="%s" colors="false">
    <testsuites>
        <testsuite name="extension-fixture">
            <file>%s</file>
        </testsuite>
    </testsuites>
    <extensions>
        <bootstrap class="Threadable\QalityPlus\PhpUnit\QalityPlusExtension">
            <parameter name="directory" value="%s"/>
            <parameter name="schema_version" value="1"/>
            <parameter name="run_id" value="extension-integration"/>
        </bootstrap>
    </extensions>
</phpunit>
XML;
        $xml = sprintf(
            $xml,
            $this->xmlPath($root.'/vendor/autoload.php'),
            $this->xmlPath(__DIR__.'/../Fixtures/ExtensionFixtureTest.php'),
            $this->xmlPath($directory),
        );
        $this->makeDirectory($directory);
        file_put_contents($configuration, $xml);

        try {
            $process = new Process([
                PHP_BINARY,
                $root.'/vendor/bin/phpunit',
                '--configuration',
                $configuration,
            ], $root);
            $process->run();

            self::assertNotSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());

            $records = (new JsonlResultReader)->readPath($directory);
            $byName = [];

            foreach ($records as $record) {
                $byName[$record['test']['name']] = $record;
            }

            self::assertSame('passed', $byName['test_annotated_passes']['status']);
            self::assertSame('QA-EXT-123', $byName['test_annotated_passes']['qality']['issue_key']);
            self::assertSame('failed', $byName['test_fails']['status']);
            self::assertSame('error', $byName['test_errors']['status']);
            self::assertSame('skipped', $byName['test_skipped']['status']);
            self::assertSame('incomplete', $byName['test_incomplete']['status']);
            self::assertSame('risky', $byName['test_risky']['status']);
        } finally {
            $this->removeFiles($directory, $configuration);
        }
    }

    private function xmlPath(string $path): string
    {
        return htmlspecialchars($path, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function makeDirectory(string $directory): void
    {
        mkdir($directory, 0775, true);
    }

    private function removeFiles(string $directory, string $configuration): void
    {
        foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }

        if (is_file($configuration)) {
            unlink($configuration);
        }
    }
}
