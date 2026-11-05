<?php

declare(strict_types=1);

namespace Duyler\Yaml\Test\Unit\Parser;

use Duyler\Yaml\Exception\ParseException;
use Duyler\Yaml\Internal\Parser\Limits;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Duyler\Yaml\Exception\DepthLimitExceededException;
use Error;

class LimitsTest extends TestCase
{
    #[Test]
    public function defaults_match_architecture_limits(): void
    {
        $limits = new Limits();

        self::assertSame(256, $limits->maxDepth);
        self::assertSame(512, $limits->maxAliases);
    }

    #[Test]
    public function limits_accept_custom_values(): void
    {
        $limits = new Limits(maxDepth: 8, maxAliases: 16);

        self::assertSame(8, $limits->maxDepth);
        self::assertSame(16, $limits->maxAliases);
    }

    #[Test]
    public function limits_are_immutable(): void
    {
        $limits = new Limits();

        $this->expectException(Error::class);

        $limits->maxDepth = 1;
    }

    #[Test]
    public function depth_limit_exception_extends_parse_contract(): void
    {
        $exception = new DepthLimitExceededException(
            message: 'Maximum node depth exceeded',
            lineNumber: 1,
            column: 1,
        );

        self::assertInstanceOf(ParseException::class, $exception);
    }
}
