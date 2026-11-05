<?php

declare(strict_types=1);

namespace Duyler\Yaml\Test\Unit\Composer;

use Duyler\Yaml\Exception\DuplicateKeyException;
use Duyler\Yaml\Exception\InvalidKeyTypeException;
use Duyler\Yaml\Exception\InvalidTagValueException;
use Duyler\Yaml\Exception\SyntaxException;
use Duyler\Yaml\Exception\UnsupportedTagException;
use Duyler\Yaml\Contract\TagHandlerInterface;
use Duyler\Yaml\Internal\Composer\AnchorRegistry;
use Duyler\Yaml\Internal\Composer\BuiltinTagResolver;
use Duyler\Yaml\Internal\Composer\Composer;
use Duyler\Yaml\Internal\Composer\ScalarResolver;
use Duyler\Yaml\Internal\Parser\Limits;
use Duyler\Yaml\Internal\Node\AliasNode;
use Duyler\Yaml\Internal\Node\DocumentNode;
use Duyler\Yaml\Internal\Node\MappingEntry;
use Duyler\Yaml\Internal\Node\MappingNode;
use Duyler\Yaml\Internal\Node\ScalarNode;
use Duyler\Yaml\Internal\Node\SequenceNode;
use Duyler\Yaml\Internal\Parser\ParseContext;
use Duyler\Yaml\Internal\Parser\Parser;
use Duyler\Yaml\Internal\Scanner\Position;
use Duyler\Yaml\Internal\Scanner\ScalarStyle;
use Duyler\Yaml\Internal\Scanner\Scanner;
use Duyler\Yaml\TaggedValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Duyler\Yaml\Exception\AnchorNotFoundException;

class ComposerTest extends TestCase
{
    #[Test]
    #[DataProvider('composed_values')]
    public function document_composes_into_expected_php_value(string $input, mixed $expected): void
    {
        self::assertSame($expected, $this->compose($input));
    }

    public static function composed_values(): array
    {
        return [
            'plain_scalar_document' => ['plain', 'plain'],
            'int_scalar_document' => ['42', 42],
            'null_scalar_document' => ['~', null],
            'bool_scalar_document' => ['true', true],
            'equals_scalar_document' => ['=', '='],
            'deliberate_deviation_from_1_1_yes_stays_string' => ['yes', 'yes'],
            'deliberate_deviation_from_1_1_010_stays_string' => ['010', '010'],
            'deliberate_deviation_from_1_1_1000_stays_string' => ['1_000', '1_000'],
            'single_quoted_document' => ["'a ''b'''", "a 'b'"],
            'double_quoted_escapes_document' => ['"a\\n\\t\\"b\\""', "a\n\t\"b\""],
            'simple_mapping' => ["a: 1\nb: 2", ['a' => 1, 'b' => 2]],
            'mapping_value_types' => [
                "s: text\ni: 7\nf: 0.5\nb: true\nn: ~",
                ['s' => 'text', 'i' => 7, 'f' => 0.5, 'b' => true, 'n' => null],
            ],
            'nested_mapping' => [
                "a:\n  b:\n    c: 3",
                ['a' => ['b' => ['c' => 3]]],
            ],
            'root_sequence' => ["- 1\n- two\n- true", [1, 'two', true]],
            'sequence_in_mapping' => [
                "a:\n  - 1\n  - 2",
                ['a' => [1, 2]],
            ],
            'null_values' => ["a:\nb:", ['a' => null, 'b' => null]],
            'empty_sequence' => ['[]', []],
            'empty_mapping' => ['{}', []],
            'nested_flow' => ['{a: [1, {b: 2}]}', ['a' => [1, ['b' => 2]]]],
            'flow_single_pair' => ['[a: 1]', [['a' => 1]]],
            'literal_block_scalar' => [
                "a: |\n  line1\n  line2",
                ['a' => "line1\nline2\n"],
            ],
            'folded_block_scalar' => [
                "a: >\n  folded\n  line",
                ['a' => "folded line\n"],
            ],
            'block_scalar_strip_chomping' => [
                "a: |-\n  x",
                ['a' => 'x'],
            ],
            'block_scalar_keep_chomping' => [
                "a: |+\n  x\n\n",
                ['a' => "x\n\n"],
            ],
            'block_scalar_explicit_indent' => [
                "a: |2\n   x",
                ['a' => " x\n"],
            ],
            'anchors_and_aliases_resolve_to_same_value' => [
                "a: &v x\nb: *v",
                ['a' => 'x', 'b' => 'x'],
            ],
            'alias_to_sequence_copies_value' => [
                "a: &v [1, 2]\nb: *v\nc: 3",
                ['a' => [1, 2], 'b' => [1, 2], 'c' => 3],
            ],
            'key_order_is_preserved_with_aliases' => [
                "first: &v [1]\nsecond: *v\nthird: 3",
                ['first' => [1], 'second' => [1], 'third' => 3],
            ],
            'tagged_scalar_applies_tag_after_style_resolution' => [
                '!!str 12',
                '12',
            ],
            'int_tag_resolves_quoted_string' => [
                '!!int "12"',
                12,
            ],
            'null_tag_resolves_quoted_empty_string' => [
                '!!null ""',
                null,
            ],
            'seq_tag_keeps_collection_identity' => [
                '!!seq [1, 2]',
                [1, 2],
            ],
            'map_tag_keeps_collection_identity' => [
                '!!map {a: 1}',
                ['a' => 1],
            ],
            'binary_tag_with_whitespace_decodes' => [
                '!!binary "aGVs\n bG8="',
                'hello',
            ],
            'php_bool_key_becomes_int' => [
                'true: v',
                [1 => 'v'],
            ],
            'php_false_key_becomes_int' => [
                'false: v',
                [0 => 'v'],
            ],
            'php_null_key_becomes_empty_string' => [
                'null: v',
                ['' => 'v'],
            ],
            'php_float_key_is_truncated' => [
                '1.5: v',
                [1 => 'v'],
            ],
            'deliberate_deviation_from_1_1_yes_key_stays_string' => [
                'yes: v',
                ['yes' => 'v'],
            ],
            'quoted_key_stays_string' => [
                '"1": v',
                ['1' => 'v'],
            ],
        ];
    }

    #[Test]
    #[DataProvider('duplicate_keys')]
    public function duplicate_key_throws_with_coordinates(
        string $input,
        int $line,
        int $column,
    ): void {
        $this->expectException(DuplicateKeyException::class);

        try {
            $this->compose($input);
            self::fail('DuplicateKeyException expected');
        } catch (DuplicateKeyException $exception) {
            self::assertSame($line . ':' . $column . ': Duplicate mapping key', $exception->getMessage());

            throw $exception;
        }
    }

    public static function duplicate_keys(): array
    {
        return [
            'duplicate_string_key' => ["a: 1\na: 2", 2, 1],
            'duplicate_after_php_bool_key_cast' => ["1: a\ntrue: b", 2, 1],
            'duplicate_after_php_null_key_cast' => ["'': a\nnull: b", 2, 1],
            'duplicate_after_php_float_key_cast' => ["1: a\n1.5: b", 2, 1],
            'duplicate_in_flow_mapping' => ['{a: 1, a: 2}', 1, 8],
        ];
    }

    #[Test]
    public function unknown_global_tag_throws_unsupported_tag_exception(): void
    {
        $this->expectException(UnsupportedTagException::class);

        try {
            $this->compose('!!itn 42');
            self::fail('UnsupportedTagException expected');
        } catch (UnsupportedTagException $exception) {
            self::assertSame('1:7: Unsupported tag "tag:yaml.org,2002:itn"', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    public function local_tag_without_handler_returns_tagged_value(): void
    {
        $result = $this->compose('!custom 42');

        self::assertInstanceOf(TaggedValue::class, $result);
        self::assertSame('!custom', $result->tag);
        self::assertSame(42, $result->value);
    }

    #[Test]
    public function local_tag_with_handler_returns_handler_result(): void
    {
        $handler = new class implements TagHandlerInterface {
            public function resolve(TaggedValue $value): mixed
            {
                return ['handled' => $value->tag, 'value' => $value->value];
            }
        };
        $composer = new Composer(new ScalarResolver(), new AnchorRegistry(), new BuiltinTagResolver(), ['!custom' => $handler]);
        $context = new ParseContext(file: null, limits: new Limits());
        $documents = new Parser()->parse(new Scanner()->scan('!custom 42'), $context);

        self::assertSame(['handled' => '!custom', 'value' => 42], $composer->compose($documents[0], $context));
    }

    #[Test]
    public function handler_receives_style_resolved_value(): void
    {
        $seen = [];
        $handler = new class ($seen) implements TagHandlerInterface {
            private array $sink;

            public function __construct(array &$sink)
            {
                $this->sink = &$sink;
            }

            public function resolve(TaggedValue $value): mixed
            {
                $this->sink = ['tag' => $value->tag, 'value' => $value->value];

                return $value->value;
            }
        };
        $composer = new Composer(new ScalarResolver(), new AnchorRegistry(), new BuiltinTagResolver(), ['!custom' => $handler]);
        $context = new ParseContext(file: null, limits: new Limits());
        $documents = new Parser()->parse(new Scanner()->scan('!custom "12"'), $context);

        self::assertSame('12', $composer->compose($documents[0], $context));
        self::assertSame(['tag' => '!custom', 'value' => '12'], $seen);
    }

    #[Test]
    public function tagged_value_nested_in_mapping_without_handler(): void
    {
        $result = $this->compose("key: !custom v");

        self::assertInstanceOf(TaggedValue::class, $result['key']);
        self::assertSame('!custom', $result['key']->tag);
        self::assertSame('v', $result['key']->value);
    }

    #[Test]
    public function tagged_collection_without_handler_keeps_whole_collection(): void
    {
        $result = $this->compose("list: !custom [1, 2]");

        self::assertInstanceOf(TaggedValue::class, $result['list']);
        self::assertSame('!custom', $result['list']->tag);
        self::assertSame([1, 2], $result['list']->value);
    }

    #[Test]
    public function bool_tag_rejects_non_boolean_value_with_coordinates(): void
    {
        $this->expectException(InvalidTagValueException::class);

        try {
            $this->compose('!!bool x');
            self::fail('InvalidTagValueException expected');
        } catch (InvalidTagValueException $exception) {
            self::assertSame('1:8: Invalid value for the "!!bool" tag', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    public function binary_tag_rejects_garbage_with_coordinates(): void
    {
        $this->expectException(InvalidTagValueException::class);

        try {
            $this->compose('!!binary "***"');
            self::fail('InvalidTagValueException expected');
        } catch (InvalidTagValueException $exception) {
            self::assertSame('1:10: Invalid value for the "!!binary" tag', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    public function seq_tag_rejects_scalar_value(): void
    {
        $this->expectException(InvalidTagValueException::class);

        try {
            $this->compose('!!seq 12');
            self::fail('InvalidTagValueException expected');
        } catch (InvalidTagValueException $exception) {
            self::assertSame('1:7: The "tag:yaml.org,2002:seq" tag requires a collection value', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    public function map_tag_rejects_scalar_value(): void
    {
        $this->expectException(InvalidTagValueException::class);

        try {
            $this->compose('!!map 12');
            self::fail('InvalidTagValueException expected');
        } catch (InvalidTagValueException $exception) {
            self::assertSame('1:7: The "tag:yaml.org,2002:map" tag requires a collection value', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    public function merge_tag_rejects_scalar_value(): void
    {
        $this->expectException(InvalidTagValueException::class);

        try {
            $this->compose('x: !!merge y');
            self::fail('InvalidTagValueException expected');
        } catch (InvalidTagValueException $exception) {
            self::assertSame('1:12: The "!!merge" tag is only valid on the "<<" key', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    public function merge_tag_is_only_valid_on_merge_key(): void
    {
        $this->expectException(SyntaxException::class);

        try {
            $this->compose('!!merge foo: 1');
            self::fail('SyntaxException expected');
        } catch (SyntaxException $exception) {
            self::assertSame('1:9: The "!!merge" tag is only valid on the "<<" key', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    public function merge_tag_on_merge_key_participates_in_merge(): void
    {
        $input = "b: &b {a: 1}\nmerged:\n  !!merge <<: *b";

        self::assertSame(['b' => ['a' => 1], 'merged' => ['a' => 1]], $this->compose($input));
    }

    #[Test]
    #[DataProvider('merge_tag_collection_values')]
    public function merge_tag_rejects_collection_value(string $input, string $expectedMessage): void
    {
        $this->expectException(InvalidTagValueException::class);

        try {
            $this->compose($input);
            self::fail('InvalidTagValueException expected');
        } catch (InvalidTagValueException $exception) {
            self::assertSame($expectedMessage, $exception->getMessage());

            throw $exception;
        }
    }

    public static function merge_tag_collection_values(): array
    {
        return [
            'flow_mapping_value' => [
                'a: !!merge {x: 1}',
                '1:12: The "!!merge" tag is only valid on the "<<" key',
            ],
            'flow_sequence_value' => [
                'a: !!merge [1]',
                '1:12: The "!!merge" tag is only valid on the "<<" key',
            ],
            'block_mapping_value' => [
                "a: !!merge\n  b: 1",
                '2:3: The "!!merge" tag is only valid on the "<<" key',
            ],
        ];
    }

    #[Test]
    public function scalar_builtin_tag_on_collection_value_throws(): void
    {
        $this->expectException(InvalidTagValueException::class);

        try {
            $this->compose("a: !!str\n  b: 1");
            self::fail('InvalidTagValueException expected');
        } catch (InvalidTagValueException $exception) {
            self::assertSame('2:3: The "tag:yaml.org,2002:str" tag requires a scalar value', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    public function unknown_global_tag_on_collection_value_throws(): void
    {
        $this->expectException(UnsupportedTagException::class);

        try {
            $this->compose("a: !!seqw\n  b: 1");
            self::fail('UnsupportedTagException expected');
        } catch (UnsupportedTagException $exception) {
            self::assertSame('2:3: Unsupported tag "tag:yaml.org,2002:seqw"', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    public function tagged_key_throws_invalid_key_type_exception(): void
    {
        $this->expectException(InvalidKeyTypeException::class);

        try {
            $this->compose('!custom k: v');
            self::fail('InvalidKeyTypeException expected');
        } catch (InvalidKeyTypeException $exception) {
            self::assertSame('1:9: Mapping key cannot be a tagged value', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    public function anchor_and_tag_cooperate_through_alias(): void
    {
        $input = "a: &x !!int \"12\"\nb: *x";

        self::assertSame(['a' => 12, 'b' => 12], $this->compose($input));
    }

    #[Test]
    public function collection_key_throws_syntax_exception(): void
    {
        $document = new DocumentNode(
            position: new Position(1, 1),
            root: new MappingNode(
                position: new Position(1, 1),
                entries: [
                    new MappingEntry(
                        key: new SequenceNode(
                            position: new Position(1, 1),
                            items: [new ScalarNode(
                                position: new Position(1, 2),
                                style: ScalarStyle::Plain,
                                raw: '1',
                            )],
                        ),
                        value: new ScalarNode(
                            position: new Position(1, 6),
                            style: ScalarStyle::Plain,
                            raw: 'v',
                        ),
                    ),
                ],
            ),
        );

        $this->expectException(SyntaxException::class);

        try {
            $this->composeDocument($document);
            self::fail('SyntaxException expected');
        } catch (SyntaxException $exception) {
            self::assertSame('1:1: Mapping keys must be scalar values', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    public function alias_key_throws_invalid_key_type_exception(): void
    {
        $document = new DocumentNode(
            position: new Position(1, 1),
            root: new MappingNode(
                position: new Position(1, 1),
                entries: [
                    new MappingEntry(
                        key: new AliasNode(position: new Position(1, 1), name: 'x'),
                        value: new ScalarNode(
                            position: new Position(1, 4),
                            style: ScalarStyle::Plain,
                            raw: 'v',
                        ),
                    ),
                ],
            ),
        );

        $this->expectException(InvalidKeyTypeException::class);

        try {
            $this->registry()->register('x', new ScalarNode(
                position: new Position(1, 1),
                style: ScalarStyle::Plain,
                raw: 'x',
            ));
            $this->composeDocument($document);
            self::fail('InvalidKeyTypeException expected');
        } catch (InvalidKeyTypeException $exception) {
            self::assertSame('1:1: Mapping key cannot be an alias', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    public function unknown_alias_throws_anchor_not_found_with_alias_coordinates(): void
    {
        $this->expectException(AnchorNotFoundException::class);

        try {
            $this->compose('y: *nope');
            self::fail('AnchorNotFoundException expected');
        } catch (AnchorNotFoundException $exception) {
            self::assertSame('1:4: Unknown anchor "nope"', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    public function composer_uses_injected_collaborators_without_static_state(): void
    {
        $first = $this->compose('a: 1');
        $second = $this->compose('b: 2');

        self::assertSame(['a' => 1], $first);
        self::assertSame(['b' => 2], $second);
    }

    #[Test]
    public function unexpected_node_type_throws_syntax_exception(): void
    {
        $document = new DocumentNode(
            position: new Position(1, 1),
            root: new MappingNode(
                position: new Position(1, 1),
                entries: [
                    new MappingEntry(
                        key: new ScalarNode(
                            position: new Position(1, 1),
                            style: ScalarStyle::Plain,
                            raw: 'a',
                        ),
                        value: new DocumentNode(
                            position: new Position(1, 1),
                            root: new ScalarNode(
                                position: new Position(1, 1),
                                style: ScalarStyle::Plain,
                                raw: 'x',
                            ),
                        ),
                    ),
                ],
            ),
        );

        $this->expectException(SyntaxException::class);

        try {
            $this->composeDocument($document);
            self::fail('SyntaxException expected');
        } catch (SyntaxException $exception) {
            self::assertSame('1:1: Unknown node type', $exception->getMessage());

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

    private function composeDocument(DocumentNode $document, ?ParseContext $context = null): mixed
    {
        $composer = new Composer(new ScalarResolver(), $this->registry());

        return $composer->compose($document, $context ?? new ParseContext(file: null, limits: new Limits()));
    }

    private function registry(): AnchorRegistry
    {
        return new AnchorRegistry();
    }
}
