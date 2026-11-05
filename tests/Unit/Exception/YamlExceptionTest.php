<?php

declare(strict_types=1);

namespace Duyler\Yaml\Test\Unit\Exception;

use Duyler\Yaml\Exception\YamlException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Exception;

class YamlExceptionTest extends TestCase
{
    #[Test]
    public function extends_base_exception(): void
    {
        $exception = new YamlException('message');

        self::assertInstanceOf(Exception::class, $exception);
        self::assertSame('message', $exception->getMessage());
    }
}
