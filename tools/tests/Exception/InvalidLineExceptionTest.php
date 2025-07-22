<?php

declare(strict_types=1);

namespace Remorhaz\UCD\Tool\Test\Exception;

use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Remorhaz\UCD\Tool\Exception\InvalidLineException;

#[CoversClass(InvalidLineException::class)]
final class InvalidLineExceptionTest extends TestCase
{
    public function testGetMessage_Constructed_ReturnsMatchingValue(): void
    {
        $exception = new InvalidLineException('a');
        self::assertSame('Invalid line format: a', $exception->getMessage());
    }

    public function testGetLineText_ConstructedWithValue_ReturnsSameValue(): void
    {
        $exception = new InvalidLineException('a');
        self::assertSame('a', $exception->getLineText());
    }

    public function testGetCode_Always_ReturnsZero(): void
    {
        $exception = new InvalidLineException('a');
        self::assertSame(0, $exception->getCode());
    }

    public function testGetPrevious_ConstructedWithoutPrevious_ReturnsNull(): void
    {
        $exception = new InvalidLineException('a');
        self::assertNull($exception->getPrevious());
    }

    public function testGetPrevious_ConstructedWithPrevious_ReturnsSameInstance(): void
    {
        $previous = new Exception();
        $exception = new InvalidLineException('a', $previous);
        self::assertSame($previous, $exception->getPrevious());
    }
}
