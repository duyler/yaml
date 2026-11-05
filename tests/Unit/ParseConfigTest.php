<?php

declare(strict_types=1);

namespace Duyler\Yaml\Test\Unit;

use Duyler\Yaml\Contract\TagHandlerInterface;
use Duyler\Yaml\ParseConfig;
use Duyler\Yaml\TaggedValue;
use PHPUnit\Framework\Attributes\Test;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Error;

use const PHP_INT_MAX;

class ParseConfigTest extends TestCase
{
    #[Test]
    public function defaults_are_safe(): void
    {
        $config = new ParseConfig();

        self::assertSame(256, $config->maxDepth);
        self::assertSame(512, $config->maxAliases);
        self::assertSame([], $config->tagHandlers);
    }

    #[Test]
    public function constants_match_defaults(): void
    {
        self::assertSame(256, ParseConfig::DEFAULT_MAX_DEPTH);
        self::assertSame(512, ParseConfig::DEFAULT_MAX_ALIASES);
    }

    #[Test]
    public function holds_custom_values(): void
    {
        $handler = new class implements TagHandlerInterface {
            public function resolve(TaggedValue $value): mixed
            {
                return $value->value;
            }
        };
        $config = new ParseConfig(maxDepth: 32, maxAliases: 8, tagHandlers: ['!custom' => $handler]);

        self::assertSame(32, $config->maxDepth);
        self::assertSame(8, $config->maxAliases);
        self::assertSame(['!custom' => $handler], $config->tagHandlers);
    }

    #[Test]
    #[DataProvider('rejected_limits')]
    public function limits_outside_allowed_range_reject_with_argument_exception(string $option, int $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ParseConfig(maxDepth: 'maxDepth' === $option ? $value : 256, maxAliases: 'maxAliases' === $option ? $value : 512);
    }

    public static function rejected_limits(): array
    {
        return [
            'zero_depth' => ['maxDepth', 0],
            'negative_depth' => ['maxDepth', -5],
            'depth_above_ceiling' => ['maxDepth', ParseConfig::MAX_ALLOWED_LIMIT + 1],
            'php_int_max_depth' => ['maxDepth', PHP_INT_MAX],
            'zero_aliases' => ['maxAliases', 0],
            'negative_aliases' => ['maxAliases', -1],
            'aliases_above_ceiling' => ['maxAliases', ParseConfig::MAX_ALLOWED_LIMIT + 1],
            'php_int_max_aliases' => ['maxAliases', PHP_INT_MAX],
        ];
    }

    #[Test]
    public function ceiling_boundary_values_are_accepted(): void
    {
        $config = new ParseConfig(maxDepth: ParseConfig::MAX_ALLOWED_LIMIT, maxAliases: ParseConfig::MAX_ALLOWED_LIMIT);

        self::assertSame(ParseConfig::MAX_ALLOWED_LIMIT, $config->maxDepth);
        self::assertSame(ParseConfig::MAX_ALLOWED_LIMIT, $config->maxAliases);
    }

    #[Test]
    public function is_readonly(): void
    {
        $config = new ParseConfig();

        $this->expectException(Error::class);

        $config->maxDepth = 1;
    }
}
