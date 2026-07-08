<?php

declare(strict_types=1);

namespace LocalVisualDiff\Compare;

use EmptyIterator;
use IteratorAggregate;
use RuntimeException;
use Traversable;

/**
 * This class contains temp-backed storage for changed pixel runs.
 *
 * @author Dmitry Dulepov <dmitry.dulepov@gmail.com>
 *
 * @implements IteratorAggregate<int, array{y:int,startX:int,endX:int}>
 */
final class ChangedRunStore implements IteratorAggregate
{
    protected const RECORD_SIZE = 12;

    protected readonly string $path;

    /** @var resource|null */
    protected mixed $handle;

    protected int $runCount = 0;

    protected ?int $minY = null;

    protected ?int $maxY = null;

    public function __construct(?string $directory = null)
    {
        $path = tempnam($directory ?? sys_get_temp_dir(), 'lvd-runs-');

        if ($path === false) {
            throw new RuntimeException('Could not create temporary changed-run storage.');
        }

        $handle = fopen($path, 'c+b');

        if ($handle === false) {
            @unlink($path);
            throw new RuntimeException(sprintf('Could not open temporary changed-run storage: %s', $path));
        }

        $this->path = $path;
        $this->handle = $handle;
    }

    /**
     * Store one horizontal run of changed pixels.
     */
    public function add(int $y, int $startX, int $endX): void
    {
        if ($this->handle === null) {
            throw new RuntimeException('Changed-run storage is already closed.');
        }

        $record = pack('N3', $y, $startX, $endX);

        if (fwrite($this->handle, $record) !== self::RECORD_SIZE) {
            throw new RuntimeException(sprintf('Could not write changed-run storage: %s', $this->path));
        }

        $this->runCount++;
        $this->minY = $this->minY === null ? $y : min($this->minY, $y);
        $this->maxY = $this->maxY === null ? $y : max($this->maxY, $y);
    }

    /**
     * Return the vertical bounds covered by all stored runs.
     *
     * @return array{minY:int,maxY:int}|null
     */
    public function bounds(): ?array
    {
        if ($this->minY === null || $this->maxY === null) {
            return null;
        }

        return [
            'minY' => $this->minY,
            'maxY' => $this->maxY,
        ];
    }

    public function count(): int
    {
        return $this->runCount;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return Traversable<int, array{y:int,startX:int,endX:int}>
     */
    public function getIterator(): Traversable
    {
        if ($this->runCount === 0) {
            return new EmptyIterator();
        }

        if ($this->handle !== null) {
            fflush($this->handle);
        }

        $readHandle = fopen($this->path, 'rb');

        if ($readHandle === false) {
            throw new RuntimeException(sprintf('Could not read changed-run storage: %s', $this->path));
        }

        try {
            while (!feof($readHandle)) {
                $record = fread($readHandle, self::RECORD_SIZE);

                if ($record === '' || $record === false) {
                    break;
                }

                if (strlen($record) !== self::RECORD_SIZE) {
                    throw new RuntimeException(sprintf('Corrupt changed-run storage: %s', $this->path));
                }

                $run = unpack('Ny/NstartX/NendX', $record);

                if (!is_array($run)) {
                    throw new RuntimeException(sprintf('Could not decode changed-run storage: %s', $this->path));
                }

                yield [
                    'y' => (int) $run['y'],
                    'startX' => (int) $run['startX'],
                    'endX' => (int) $run['endX'],
                ];
            }
        } finally {
            fclose($readHandle);
        }
    }

    /**
     * Close the storage and remove its backing temp file.
     */
    public function cleanup(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }

        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    public function __destruct()
    {
        $this->cleanup();
    }
}
