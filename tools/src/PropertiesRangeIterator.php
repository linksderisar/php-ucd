<?php

declare(strict_types=1);

namespace Remorhaz\UCD\Tool;

use Iterator;
use IteratorAggregate;
use Remorhaz\IntRangeSets\Range;
use Remorhaz\IntRangeSets\RangeInterface;
use SplFileObject;
use Throwable;

/**
 * @template-implements IteratorAggregate<string, RangeInterface>
 */
final class PropertiesRangeIterator implements IteratorAggregate
{
    /**
     * @var callable
     */
    private mixed $onProgress;

    public function __construct(
        private readonly SplFileObject $file,
        callable $onProgress,
    ) {
        $this->onProgress = $onProgress;
    }

    /**
     * @return Iterator<string, RangeInterface>
     */
    #[\Override]
    public function getIterator(): Iterator
    {
        while (!$this->file->eof()) {
            $line = $this->fetchNextLine($this->file);
            if (!isset($line)) {
                continue;
            }
            yield from $this->fetchPropertyRange($line);

            ($this->onProgress)(strlen($line));
        }
    }

    private function fetchNextLine(SplFileObject $file): ?string
    {
        try {
            $line = $file->fgets();
        } catch (Throwable $e) {
            throw new Exception\LineNotReadException($file->getFilename(), $e);
        }

        return '' == $line ? null : $line;
    }

    /**
     * @param string $line
     * @return Iterator<string, RangeInterface>
     */
    private function fetchPropertyRange(string $line): Iterator
    {
        $dataWithComment = explode('#', $line, 2);
        $data = trim($dataWithComment[0] ?? '');
        if ('' == $data) {
            return;
        }
        $rangeWithProp = explode(';', $data);
        $unSplitRange = trim($rangeWithProp[0] ?? '');
        $prop = trim($rangeWithProp[1] ?? '');
        if (!isset($unSplitRange, $prop)) {
            throw new Exception\InvalidLineException($line);
        }
        $splitRange = explode('..', $unSplitRange);
        $start = hexdec($splitRange[0]);
        $finish = isset($splitRange[1])
            ? hexdec($splitRange[1])
            : $start;

        try {
            yield $prop => new Range($start, $finish);
        } catch (Throwable $e) {
            throw new Exception\RangeNotCreatedException($e);
        }
    }
}
