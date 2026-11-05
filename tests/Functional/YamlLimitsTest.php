<?php

declare(strict_types=1);

namespace Duyler\Yaml\Test\Functional;

use Duyler\Yaml\Exception\AliasCycleException;
use Duyler\Yaml\Exception\AliasLimitExceededException;
use Duyler\Yaml\Exception\DepthLimitExceededException;
use Duyler\Yaml\ParseConfig;
use Duyler\Yaml\Yaml;
use PHPUnit\Framework\Attributes\Test;

use PHPUnit\Framework\TestCase;

use function implode;

use function str_repeat;

class YamlLimitsTest extends TestCase
{
    #[Test]
    public function depth_above_default_limit_throws(): void
    {
        $input = str_repeat('[', 257) . str_repeat(']', 257);

        $this->expectException(DepthLimitExceededException::class);

        try {
            Yaml::parse($input);
            self::fail(DepthLimitExceededException::class . ' expected');
        } catch (DepthLimitExceededException $exception) {
            self::assertSame(1, $exception->lineNumber);

            throw $exception;
        }
    }

    #[Test]
    public function depth_at_custom_limit_throws(): void
    {
        $config = new ParseConfig(maxDepth: 2);

        $this->expectException(DepthLimitExceededException::class);

        Yaml::parse('[[[[1]]]]', $config);
    }

    #[Test]
    public function alias_513_throws_limit_exceeded(): void
    {
        $aliases = str_repeat('*x, ', 512) . '*x';
        $input = "a: &x 1\nb: [$aliases]";

        $this->expectException(AliasLimitExceededException::class);

        try {
            Yaml::parse($input);
            self::fail(AliasLimitExceededException::class . ' expected');
        } catch (AliasLimitExceededException $exception) {
            self::assertSame(2, $exception->lineNumber);

            throw $exception;
        }
    }

    #[Test]
    public function custom_max_aliases_threshold_is_enforced(): void
    {
        $config = new ParseConfig(maxAliases: 4);
        $input = "a: &x 1\nb: [*x, *x, *x, *x, *x]";

        $this->expectException(AliasLimitExceededException::class);

        try {
            Yaml::parse($input, $config);
            self::fail(AliasLimitExceededException::class . ' expected');
        } catch (AliasLimitExceededException $exception) {
            self::assertSame(2, $exception->lineNumber);

            throw $exception;
        }
    }

    #[Test]
    public function billion_laughs_fixture_is_stopped_by_limit(): void
    {
        $input = "a: &a [0, 0, 0, 0, 0, 0, 0, 0, 0, 0]\n"
            . 'b: &b [' . str_repeat('*a, ', 9) . '*a]'
            . "\nc: &c [" . str_repeat('*b, ', 9) . '*b]'
            . "\nd: &d [" . str_repeat('*c, ', 9) . '*c]'
            . "\ne: [" . str_repeat('*d, ', 9) . '*d]';

        $this->expectException(AliasLimitExceededException::class);

        Yaml::parse($input);
    }

    #[Test]
    public function cyclic_alias_throws_cycle_exception(): void
    {
        $this->expectException(AliasCycleException::class);

        try {
            Yaml::parse('a: &x [*x]');
            self::fail(AliasCycleException::class . ' expected');
        } catch (AliasCycleException $exception) {
            self::assertSame('1:8: Alias cycle detected on anchor "x"', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    public function block_nesting_depth_above_limit_throws(): void
    {
        $lines = [];
        for ($i = 0; $i < 257; $i++) {
            $lines[] = str_repeat(' ', $i) . 'k' . $i . ':';
        }
        $input = implode("\n", $lines);

        $this->expectException(DepthLimitExceededException::class);

        try {
            Yaml::parse($input);
            self::fail(DepthLimitExceededException::class . ' expected');
        } catch (DepthLimitExceededException $exception) {
            self::assertSame(257, $exception->lineNumber);

            throw $exception;
        }
    }

    #[Test]
    public function composer_depth_counter_stops_alias_unfolding_beyond_limit(): void
    {
        $lines = ['n1: &n1', '  child: 0'];
        for ($i = 2; $i <= 20; $i++) {
            $lines[] = "n$i: &n$i";
            $lines[] = '  child: *n' . ($i - 1);
        }
        $lines[] = 'end: *n20';
        $config = new ParseConfig(maxDepth: 10);

        $this->expectException(DepthLimitExceededException::class);

        try {
            Yaml::parse(implode("\n", $lines), $config);
            self::fail(DepthLimitExceededException::class . ' expected');
        } catch (DepthLimitExceededException $exception) {
            self::assertLessThan(40, $exception->lineNumber);

            throw $exception;
        }
    }

    #[Test]
    public function merge_unfolding_is_limited_by_composer_depth(): void
    {
        $lines = ['m1: &m1 {k: 0}'];
        for ($i = 2; $i <= 12; $i++) {
            $lines[] = "m$i: &m$i {<<: *m" . ($i - 1) . '}';
        }
        $lines[] = 'end: {<<: *m12}';
        $config = new ParseConfig(maxDepth: 10);

        $this->expectException(DepthLimitExceededException::class);

        try {
            Yaml::parse(implode("\n", $lines), $config);
            self::fail(DepthLimitExceededException::class . ' expected');
        } catch (DepthLimitExceededException $exception) {
            self::assertStringEndsWith('Maximum merge unfolding depth exceeded', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    public function depth_inside_limit_parses(): void
    {
        $input = str_repeat('[', 256) . str_repeat(']', 256);

        self::assertIsArray(Yaml::parse($input));
    }

    #[Test]
    public function block_sequence_nesting_depth_above_limit_throws(): void
    {
        $lines = [];
        for ($i = 0; $i < 257; $i++) {
            $lines[] = str_repeat(' ', $i) . '-';
        }
        $input = implode("\n", $lines);

        $this->expectException(DepthLimitExceededException::class);

        try {
            Yaml::parse($input);
            self::fail(DepthLimitExceededException::class . ' expected');
        } catch (DepthLimitExceededException $exception) {
            self::assertSame(257, $exception->lineNumber);

            throw $exception;
        }
    }
}
