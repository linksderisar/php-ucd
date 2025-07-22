<?php

declare(strict_types=1);

namespace Remorhaz\UCD\Tool;

use Closure;
use Iterator;
use IteratorAggregate;
use Remorhaz\IntRangeSets\Range;
use Remorhaz\IntRangeSets\RangeInterface;
use Safe;
use SplFileObject;
use Throwable;

use function strlen;

/**
 * @template-implements IteratorAggregate<string, RangeInterface>
 */
final class UnicodeDataRangeIterator implements IteratorAggregate
{
    /**
     * @var callable
     */
    private mixed $onProgress;

    private ?int $code = null;

    private ?string $name = null;

    private ?string $prop = null;

    private ?int $lastCode = null;

    private ?string $lastProp = null;

    private ?int $rangeStart = null;

    private array $namedStarts = [];

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
            $range = $this->fetchUnicodeDataRange($line);
            if (isset($range, $this->lastProp)) {
                yield $this->lastProp => $range;
            }

            $this->lastCode = $this->code;
            $this->lastProp = $this->prop;

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
     * @psalm-assert !null $this->code
     * @psalm-assert !null $this->name
     * @psalm-assert !null $this->prop
     */
    private function parseUnicodeDataLineLine(string $line): void
    {
        $splitLine = explode(';', $line);
        $codeHex = $splitLine[0] ?? null;
        $name = $splitLine[1] ?? null;
        $prop = $splitLine[2] ?? null;
        if (!isset($codeHex, $name, $prop)) {
            throw new Exception\InvalidLineException($line);
        }
        $this->code = (int) hexdec($codeHex);
        $this->name = $name;
        $this->prop = $prop;
    }

    private function fetchUnicodeDataRange(string $line): ?RangeInterface
    {
        $this->parseUnicodeDataLineLine($line);

        [$firstName, $lastName] = $this->parseRangeBoundary($this->name);
        if (isset($firstName)) {
            $this->namedStarts[$firstName] = $this->code;
            $this->rangeStart = null;

            return null;
        }

        if (isset($lastName)) {
            if (
                !isset($this->namedStarts[$lastName], $this->lastCode) ||
                isset($this->rangeStart) ||
                $this->lastCode !== $this->namedStarts[$lastName]
            ) {
                throw new Exception\InvalidLineException($line);
            }

            return $this->createRange($this->lastCode, $this->code);
        }

        if ($this->prop === $this->lastProp && $this->code - 1 === $this->lastCode) {
            return null;
        }

        $range = isset($this->rangeStart, $this->lastCode)
            ? $this->createRange($this->rangeStart, $this->lastCode)
            : null;

        $this->rangeStart = $this->code;

        return $range;
    }

    /**
     * @param string $name
     * @return array{string|null, string|null}
     */
    private function parseRangeBoundary(string $name): array
    {
        try {
            $isFirst = 1 === Safe\preg_match('#^<(.+), First>$#', $name, $matches);
            if ($isFirst) {
                return [$matches[1] ?? null, null];
            }

            $isLast = 1 === Safe\preg_match('#^<(.+), Last>$#', $name, $matches);

            return $isLast
                ? [null, $matches[1] ?? null]
                : [null, null];
        } catch (Throwable $e) {
            throw new Exception\CodePointNameNotParsedException($name, $e);
        }
    }

    private function createRange(int $start, ?int $finish): RangeInterface
    {
        try {
            return new Range($start, $finish);
        } catch (Throwable $e) {
            throw new Exception\RangeNotCreatedException($e);
        }
    }
}
