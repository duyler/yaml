<?php

declare(strict_types=1);

namespace Duyler\Yaml\Test\Unit\Composer;

use Duyler\Yaml\Exception\AliasCycleException;
use Duyler\Yaml\Exception\AliasLimitExceededException;
use Duyler\Yaml\Exception\DepthLimitExceededException;
use Duyler\Yaml\Exception\DuplicateKeyException;
use Duyler\Yaml\Exception\MergeSourceException;
use Duyler\Yaml\Internal\Composer\AnchorRegistry;
use Duyler\Yaml\Internal\Composer\Composer;
use Duyler\Yaml\Internal\Composer\ScalarResolver;
use Duyler\Yaml\Internal\Parser\Limits;
use Duyler\Yaml\Internal\Parser\ParseContext;
use Duyler\Yaml\Internal\Node\DocumentNode;
use Duyler\Yaml\Internal\Parser\Parser;
use Duyler\Yaml\Internal\Scanner\Scanner;
use PHPUnit\Framework\Attributes\DataProvider;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Duyler\Yaml\Exception\AnchorNotFoundException;
use Throwable;

use function str_repeat;

class MergeKeysTest extends TestCase
{
    #[Test]
    #[DataProvider('merge_matrix')]
    public function merge_key_composes_according_to_yaml_org_merge_semantics(string $input, array $expected): void
    {
        self::assertSame($expected, $this->compose($input));
    }

    public static function merge_matrix(): array
    {
        return [
            'single_alias_source' => [
                "base: &b\n  a: 1\n  b: 2\nmerged:\n  <<: *b",
                ['base' => ['a' => 1, 'b' => 2], 'merged' => ['a' => 1, 'b' => 2]],
            ],
            'explicit_keys_win_over_merged' => [
                "base: &b\n  a: 1\n  b: 2\nmerged:\n  <<: *b\n  b: 3",
                ['base' => ['a' => 1, 'b' => 2], 'merged' => ['a' => 1, 'b' => 3]],
            ],
            'explicit_keys_win_even_when_merge_comes_later' => [
                "m: &m {b: 2}\nb: 3\nmerged:\n  <<: *m",
                ['m' => ['b' => 2], 'b' => 3, 'merged' => ['b' => 2]],
            ],
            'list_of_aliases_earlier_entries_win' => [
                "first: &f\n  a: 1\n  b: 1\nsecond: &s\n  a: 2\n  c: 2\nmerged:\n  <<: [*f, *s]",
                [
                    'first' => ['a' => 1, 'b' => 1],
                    'second' => ['a' => 2, 'c' => 2],
                    'merged' => ['a' => 1, 'b' => 1, 'c' => 2],
                ],
            ],
            'list_of_aliases_partial_overlap' => [
                "x: &x\n  k: from_x\ny: &y\n  k: from_y\n  only_y: yes\nmerged:\n  <<: [*x, *y]",
                [
                    'x' => ['k' => 'from_x'],
                    'y' => ['k' => 'from_y', 'only_y' => 'yes'],
                    'merged' => ['k' => 'from_x', 'only_y' => 'yes'],
                ],
            ],
            'merged_keys_compose_values' => [
                "base: &b\n  nested:\n    deep: 1\nmerged:\n  <<: *b",
                ['base' => ['nested' => ['deep' => 1]], 'merged' => ['nested' => ['deep' => 1]]],
            ],
            'merge_with_alias_source_no_explicit_keys' => [
                "m: &m\n  a: 1\nmerged:\n  <<: *m",
                ['m' => ['a' => 1], 'merged' => ['a' => 1]],
            ],
        ];
    }

    #[Test]
    #[DataProvider('merge_errors')]
    public function invalid_merge_throws_with_coordinates(
        string $input,
        string $exceptionClass,
        string $message,
        int $line,
        int $column,
    ): void {
        $this->expectException($exceptionClass);

        try {
            $this->compose($input);
            self::fail($exceptionClass . ' expected');
        } catch (Throwable $exception) {
            self::assertInstanceOf($exceptionClass, $exception);
            self::assertSame($line . ':' . $column . ': ' . $message, $exception->getMessage());

            throw $exception;
        }
    }

    public static function merge_errors(): array
    {
        return [
            'merge_source_scalar' => [
                "x: 1\ny:\n  <<: 1",
                MergeSourceException::class,
                'Merge source must be an alias or a list of aliases',
                3,
                7,
            ],
            'merge_source_null' => [
                "y:\n  <<:",
                MergeSourceException::class,
                'Merge source must be an alias or a list of aliases',
                2,
                5,
            ],
            'merge_source_sequence_with_non_alias' => [
                "x: &m {k: 1}\ny:\n  <<: [*m, 42]",
                MergeSourceException::class,
                'Merge source list must contain only aliases',
                3,
                12,
            ],
            'merge_source_alias_to_sequence' => [
                "s: &s [1]\ny:\n  <<: *s",
                MergeSourceException::class,
                'Merge source must be a mapping',
                3,
                7,
            ],
            'merge_source_list_alias_to_sequence' => [
                "s: &s [1]\ny:\n  <<: [*s]",
                MergeSourceException::class,
                'Merge source must be a mapping',
                3,
                8,
            ],
            'duplicate_merge_key' => [
                '{<<: {a: 1}, <<: {b: 2}}',
                DuplicateKeyException::class,
                'Duplicate mapping key',
                1,
                14,
            ],
        ];
    }

    #[Test]
    public function quoted_merge_key_is_regular_string_key(): void
    {
        self::assertSame(['<<' => 'v'], $this->compose("'<<': v"));
        self::assertSame(['<<' => 'v'], $this->compose('"<<": v'));
    }

    #[Test]
    public function merge_unfolding_is_limited_by_max_depth(): void
    {
        $input = 'm1: &m1 {k: 0}';
        for ($i = 2; $i <= 12; $i++) {
            $input .= "\nm$i: &m$i {<<: *m" . ($i - 1) . '}';
        }
        $input .= "\nend: {<<: *m12}";
        $context = new ParseContext(file: null, limits: new Limits(maxDepth: 10, maxAliases: 512));
        $documents = new Parser()->parse(new Scanner()->scan($input), $context);

        $this->expectException(DepthLimitExceededException::class);

        try {
            $this->composeDocument($documents[0], $context);
            self::fail('DepthLimitExceededException expected');
        } catch (DepthLimitExceededException $exception) {
            self::assertStringEndsWith('Maximum merge unfolding depth exceeded', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    public function self_referencing_alias_is_detected_as_cycle(): void
    {
        $input = 'a: &x [*x]';
        $context = new ParseContext(file: null, limits: new Limits());
        $documents = new Parser()->parse(new Scanner()->scan($input), $context);

        $this->expectException(AliasCycleException::class);

        try {
            $this->composeDocument($documents[0], $context);
            self::fail('AliasCycleException expected');
        } catch (AliasCycleException $exception) {
            self::assertSame('1:8: Alias cycle detected on anchor "x"', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    public function alias_bomb_is_stopped_by_linear_deployment_limit(): void
    {
        $input = "a: &a [0, 0, 0, 0, 0, 0, 0, 0, 0, 0]\n"
            . 'b: &b [' . str_repeat('*a, ', 9) . '*a]'
            . "\nc: &c [" . str_repeat('*b, ', 9) . '*b]'
            . "\nd: &d [" . str_repeat('*c, ', 9) . '*c]'
            . "\ne: [" . str_repeat('*d, ', 9) . '*d]';
        $this->expectException(AliasLimitExceededException::class);

        $this->compose($input);
    }

    #[Test]
    public function alias_deployments_reset_between_documents(): void
    {
        $input = "a: &v 1\n---\nb: *v";
        $context = new ParseContext(file: null, limits: new Limits());
        $documents = new Parser()->parse(new Scanner()->scan($input), $context);
        $composer = new Composer(new ScalarResolver(), new AnchorRegistry());

        $this->expectException(AnchorNotFoundException::class);

        try {
            $composer->compose($documents[0], $context);
            $composer->compose($documents[1], $context);
            self::fail('AnchorNotFoundException expected');
        } catch (AnchorNotFoundException $exception) {
            self::assertSame('3:4: Unknown anchor "v"', $exception->getMessage());

            throw $exception;
        }
    }

    private function compose(string $input): mixed
    {
        $context = new ParseContext(file: null, limits: new Limits());
        $documents = new Parser()->parse(new Scanner()->scan($input), $context);
        if ([] === $documents) {
            throw new RuntimeException('Test input produced no documents');
        }

        return $this->composeDocument($documents[0], $context);
    }

    private function composeDocument(DocumentNode $document, ParseContext $context): mixed
    {
        $composer = new Composer(new ScalarResolver(), new AnchorRegistry());

        return $composer->compose($document, $context);
    }
}
