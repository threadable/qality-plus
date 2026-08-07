<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Tests\Unit;

use Threadable\QalityPlus\Publisher\PublisherException;
use Threadable\QalityPlus\Publisher\TestCaseMappingStore;
use Threadable\QalityPlus\Tests\TestCase;

final class TestCaseMappingStoreTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }

            $directory = dirname($path);

            if (is_dir($directory) && count(scandir($directory)) === 2) {
                rmdir($directory);
            }
        }

        parent::tearDown();
    }

    public function test_it_round_trips_mappings_and_creates_parent_directories(): void
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qality-mappings-'.bin2hex(random_bytes(4)).DIRECTORY_SEPARATOR.'mapping.json';
        $this->paths[] = $path;
        $store = new TestCaseMappingStore($path);
        $mappings = ['Example::test' => ['issue_key' => 'QA-123']];

        $store->save($mappings);

        self::assertSame($mappings, $store->load());
    }

    public function test_it_rejects_invalid_mapping_json(): void
    {
        $path = $this->temporaryPath();
        file_put_contents($path, '{invalid');

        $this->expectException(PublisherException::class);
        (new TestCaseMappingStore($path))->load();
    }

    public function test_it_rejects_mappings_without_issue_keys(): void
    {
        $path = $this->temporaryPath();
        file_put_contents($path, json_encode(['Example::test' => []], JSON_THROW_ON_ERROR));

        $this->expectException(PublisherException::class);
        (new TestCaseMappingStore($path))->load();
    }

    private function temporaryPath(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qality-map-'.bin2hex(random_bytes(4)).'.json';
        $this->paths[] = $path;

        return $path;
    }
}
