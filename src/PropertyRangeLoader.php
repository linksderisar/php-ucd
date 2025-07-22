<?php

declare(strict_types=1);

namespace Remorhaz\UCD;

use Remorhaz\IntRangeSets\RangeSetInterface;

use function error_clear_last;
use function error_get_last;
use function is_string;

final class PropertyRangeLoader implements PropertyRangeLoaderInterface
{
    /**
     * @var array<string, RangeSetInterface>
     */
    private array $cache = [];

    public static function create(): self
    {
        /** @psalm-var array<string, string> $index */
        $index = require __DIR__ . '/../config/ranges.php';

        return new self(__DIR__, $index);
    }

    /**
     * @param string               $path
     * @param array<string, mixed> $index
     */
    public function __construct(
        private readonly string $path,
        private readonly array $index,
    ) {
    }

    #[\Override]
    public function getRangeSet(string $propertyName): RangeSetInterface
    {
        return $this->cache[$propertyName] ??= $this->loadRangeSet($propertyName);
    }

    private function loadRangeSet(string $propertyName): RangeSetInterface
    {
        $file = $this->index[$propertyName] ??
            throw new Exception\PropertyRangeSetNotFoundException($propertyName);
        if (!is_string($file)) {
            throw new Exception\InvalidPropertyConfigException($propertyName, $file);
        }
        $fileName = $this->path . $file;
        error_clear_last();
        /**
         * @psalm-suppress UnresolvableInclude
         * @var RangeSetInterface|false $rangeSet
         */
        $rangeSet = @include $fileName;
        if (false === $rangeSet) {
            $lastError = error_get_last();
            throw new Exception\PropertyFileNotLoadedException(
                $propertyName,
                $fileName,
                $lastError['message'] ?? null,
            );
        }

        return $rangeSet instanceof RangeSetInterface
            ? $rangeSet
            : throw new Exception\InvalidPropertyRangeSetException($propertyName, $fileName, $rangeSet);
    }
}
