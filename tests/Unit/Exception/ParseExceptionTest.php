<?php

declare(strict_types=1);

namespace Duyler\Yaml\Test\Unit\Exception;

use Duyler\Yaml\Exception\InvalidEncodingException;
use Duyler\Yaml\Exception\ParseException;
use Duyler\Yaml\Exception\YamlException;
use Error;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ParseExceptionTest extends TestCase
{
    #[Test]
    public function message_includes_file_when_file_is_defined(): void
    {
        $exception = new ParseException('boom', 3, 5, 'file.yaml');

        self::assertSame('file.yaml:3:5: boom', $exception->getMessage());
        self::assertSame('file.yaml', $exception->fileName);
        self::assertSame(3, $exception->lineNumber);
        self::assertSame(5, $exception->column);
    }

    #[Test]
    public function message_omits_file_when_file_is_null(): void
    {
        $exception = new ParseException('boom', 3, 5);

        self::assertSame('3:5: boom', $exception->getMessage());
        self::assertNull($exception->fileName);
        self::assertSame(3, $exception->lineNumber);
        self::assertSame(5, $exception->column);
    }

    #[Test]
    public function coordinates_are_at_least_one(): void
    {
        $exception = new ParseException('boom', 1, 1);

        self::assertGreaterThanOrEqual(1, $exception->lineNumber);
        self::assertGreaterThanOrEqual(1, $exception->column);
    }

    #[Test]
    public function line_number_is_immutable(): void
    {
        $exception = new ParseException('boom', 3, 5);

        $this->expectException(Error::class);

        $exception->lineNumber = 99;
    }

    #[Test]
    public function parse_exception_extends_yaml_exception(): void
    {
        $exception = new ParseException('boom', 1, 1);

        self::assertInstanceOf(YamlException::class, $exception);
    }

    #[Test]
    public function invalid_encoding_exception_keeps_parse_contract(): void
    {
        $exception = new InvalidEncodingException(
            message: 'boom',
            lineNumber: 2,
            column: 7,
        );

        self::assertInstanceOf(ParseException::class, $exception);
        self::assertSame('2:7: boom', $exception->getMessage());
        self::assertSame(2, $exception->lineNumber);
        self::assertSame(7, $exception->column);
    }
}
