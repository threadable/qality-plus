<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\PhpUnit;

use JsonException;
use RuntimeException;

final class ResultWriter
{
    private int $sequence = 0;

    private readonly string $resolvedRunId;

    /**
     * @var resource|null
     */
    private $handle = null;

    public function __construct(
        private readonly string $directory,
        private readonly int $schemaVersion = 1,
        private readonly string $runId = '',
    ) {
        $this->resolvedRunId = $runId !== '' ? $runId : date('Ymd\THis\Z').'-'.bin2hex(random_bytes(4));
    }

    public function path(): string
    {
        return rtrim($this->directory, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .'qality-results.v'.$this->schemaVersion.'.'.$this->resolvedRunId().'.jsonl';
    }

    /**
     * @param  array<string, mixed>  $result
     *
     * @throws JsonException
     */
    public function write(array $result): void
    {
        $this->open();

        $record = [
            'schema_version' => $this->schemaVersion,
            'run_id' => $this->resolvedRunId(),
            'sequence' => ++$this->sequence,
            'recorded_at' => gmdate(DATE_ATOM),
            ...$result,
        ];

        $line = json_encode(
            $record,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ).PHP_EOL;

        if (! flock($this->handle, LOCK_EX)) {
            throw new RuntimeException('Unable to lock the QAlity JSONL result file.');
        }

        try {
            if (fwrite($this->handle, $line) === false || ! fflush($this->handle)) {
                throw new RuntimeException('Unable to write the QAlity JSONL result file.');
            }
        } finally {
            flock($this->handle, LOCK_UN);
        }
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        $this->handle = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function open(): void
    {
        if (is_resource($this->handle)) {
            return;
        }

        if (! is_dir($this->directory) && ! mkdir($this->directory, 0775, true) && ! is_dir($this->directory)) {
            throw new RuntimeException(sprintf('Unable to create QAlity result directory [%s].', $this->directory));
        }

        $handle = fopen($this->path(), 'ab');

        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open QAlity result file [%s].', $this->path()));
        }

        $this->handle = $handle;
    }

    private function resolvedRunId(): string
    {
        return $this->resolvedRunId;
    }
}
