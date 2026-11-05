<?php

declare(strict_types=1);

namespace Duyler\Yaml\Test\Functional;

use Duyler\Yaml\Contract\TagHandlerInterface;
use Duyler\Yaml\Exception\InvalidEncodingException;
use Duyler\Yaml\Exception\InvalidTagValueException;
use Duyler\Yaml\Exception\MultipleDocumentsException;
use Duyler\Yaml\Exception\SyntaxException;
use Duyler\Yaml\Exception\VersionException;
use Duyler\Yaml\ParseConfig;
use Duyler\Yaml\TaggedValue;
use Duyler\Yaml\Yaml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Duyler\Yaml\Exception\DepthLimitExceededException;

class YamlParseTest extends TestCase
{
    #[Test]
    public function empty_input_parses_to_null(): void
    {
        self::assertNull(Yaml::parse(''));
    }

    #[Test]
    public function comment_only_input_parses_to_null(): void
    {
        self::assertNull(Yaml::parse('# only a comment'));
    }

    #[Test]
    public function comment_only_input_parses_to_empty_list_in_parse_all(): void
    {
        self::assertSame([], Yaml::parseAll('# only a comment'));
    }

    #[Test]
    #[DataProvider('scalar_styles')]
    public function parses_every_scalar_style(string $input, mixed $expected): void
    {
        self::assertSame($expected, Yaml::parse($input));
    }

    public static function scalar_styles(): array
    {
        return [
            'plain_int' => ['42', 42],
            'plain_bool' => ['true', true],
            'plain_null' => ['~', null],
            'plain_deliberate_yes_stays_string' => ['yes', 'yes'],
            'single_quoted' => ["'a ''b'''", "a 'b'"],
            'double_quoted' => ['"a\\n\\tb"', "a\n\tb"],
            'literal_block' => ["|\n  line1\n  line2", "line1\nline2\n"],
            'folded_block' => [">\n  line1\n  line2", "line1 line2\n"],
        ];
    }

    #[Test]
    public function parses_nested_collections(): void
    {
        $input = "root:\n  list:\n    - [1, 2]\n    - {a: b}\n  map: {deep: {deeper: 3}}\n  scalar: 42";

        self::assertSame([
            'root' => [
                'list' => [[1, 2], ['a' => 'b']],
                'map' => ['deep' => ['deeper' => 3]],
                'scalar' => 42,
            ],
        ], Yaml::parse($input));
    }

    #[Test]
    public function resolves_anchors_merge_and_tags_end_to_end(): void
    {
        $input = "base: &b {x: 1}\nmerged:\n  <<: *b\n  y: 2\ntagged: !!str 12";

        self::assertSame([
            'base' => ['x' => 1],
            'merged' => ['x' => 1, 'y' => 2],
            'tagged' => '12',
        ], Yaml::parse($input));
    }

    #[Test]
    public function local_tag_without_handler_returns_tagged_value(): void
    {
        $result = Yaml::parse('!custom 42');

        self::assertInstanceOf(TaggedValue::class, $result);
        self::assertSame('!custom', $result->tag);
        self::assertSame(42, $result->value);
    }

    #[Test]
    public function custom_tag_handler_resolves_through_config(): void
    {
        $handler = new class implements TagHandlerInterface {
            public function resolve(TaggedValue $value): mixed
            {
                return ['tag' => $value->tag, 'resolved' => $value->value];
            }
        };
        $config = new ParseConfig(tagHandlers: ['!custom' => $handler]);

        self::assertSame(
            ['tag' => '!custom', 'resolved' => 42],
            Yaml::parse('!custom 42', $config),
        );
    }

    #[Test]
    public function parse_on_multi_document_stream_throws_with_hint_and_coordinates(): void
    {
        $this->expectException(MultipleDocumentsException::class);

        try {
            Yaml::parse("a: 1\n---\nb: 2");
            self::fail(MultipleDocumentsException::class . ' expected');
        } catch (MultipleDocumentsException $exception) {
            self::assertSame(
                '2:1: The input contains multiple documents, use parseAll() to parse them all',
                $exception->getMessage(),
            );
            self::assertSame(2, $exception->lineNumber);
            self::assertSame(1, $exception->column);

            throw $exception;
        }
    }

    #[Test]
    public function unsupported_yaml_version_directive_throws(): void
    {
        $this->expectException(VersionException::class);

        try {
            Yaml::parse("%YAML 1.1\n---\na: 1");
            self::fail(VersionException::class . ' expected');
        } catch (VersionException $exception) {
            self::assertSame('1:1: Unsupported YAML version "1.1"', $exception->getMessage());
            self::assertSame(1, $exception->lineNumber);
            self::assertSame(1, $exception->column);

            throw $exception;
        }
    }

    #[Test]
    public function invalid_utf8_input_throws_before_parsing(): void
    {
        $this->expectException(InvalidEncodingException::class);

        Yaml::parse("a: \xC3\x28");
    }

    #[Test]
    public function explicit_config_limits_replace_defaults(): void
    {
        $config = new ParseConfig(maxDepth: 2);

        $this->expectException(DepthLimitExceededException::class);

        Yaml::parse('[[[[1]]]]', $config);
    }

    #[Test]
    public function node_attributes_at_end_of_input_yield_null_document(): void
    {
        self::assertNull(Yaml::parse('&anchor'));
        self::assertNull(Yaml::parse('!!null'));
    }

    #[Test]
    public function str_tag_at_end_of_input_applies_to_null_value(): void
    {
        self::assertSame('', Yaml::parse('!!str'));
    }

    #[Test]
    public function mapping_key_at_end_of_input_yields_null_value(): void
    {
        self::assertSame(['a' => null], Yaml::parse('a:'));
        self::assertSame([':' => null], Yaml::parse('::'));
    }

    #[Test]
    public function anchor_before_first_key_keeps_line_indent_baseline(): void
    {
        self::assertSame(['a' => 1, 'b' => 2], Yaml::parse("&m a: 1\nb: 2"));
    }

    #[Test]
    public function tag_before_first_key_keeps_line_indent_baseline(): void
    {
        self::assertSame(['a' => 1, 'b' => 2], Yaml::parse("!!str a: 1\nb: 2"));
    }

    #[Test]
    public function nested_anchor_before_first_key_keeps_line_indent_baseline(): void
    {
        self::assertSame(['x' => ['a' => 1, 'b' => 2]], Yaml::parse("x:\n  &m a: 1\n  b: 2"));
    }

    #[Test]
    public function nested_value_tag_with_same_line_anchor_keeps_line_indent_baseline(): void
    {
        self::assertSame(['x' => ['a' => 1, 'b' => 2]], Yaml::parse("x: !!map\n  &t a: 1\n  b: 2"));
    }

    #[Test]
    public function over_indented_sibling_after_same_line_properties_throws(): void
    {
        $this->expectException(SyntaxException::class);

        try {
            Yaml::parse("&m a: 1\n    b: 2");
            self::fail(SyntaxException::class . ' expected');
        } catch (SyntaxException $exception) {
            self::assertSame('2:5: Invalid mapping key indentation', $exception->getMessage());
            self::assertSame(2, $exception->lineNumber);
            self::assertSame(5, $exception->column);

            throw $exception;
        }
    }

    #[Test]
    public function collection_tag_before_first_key_reaches_the_key_node(): void
    {
        $this->expectException(InvalidTagValueException::class);

        try {
            Yaml::parse("!!map &m a: 1\nb: 2");
            self::fail(InvalidTagValueException::class . ' expected');
        } catch (InvalidTagValueException $exception) {
            self::assertSame(
                '1:10: The "tag:yaml.org,2002:map" tag requires a collection value',
                $exception->getMessage(),
            );

            throw $exception;
        }
    }

    #[Test]
    public function own_line_collection_tags_resolve_onto_root_collections(): void
    {
        self::assertSame(['a' => 1], Yaml::parse("!!map\na: 1"));
        self::assertSame(['a' => 1], Yaml::parse("--- !!map\na: 1"));
        self::assertSame([1, 2], Yaml::parse("!!seq\n- 1\n- 2"));
    }

    #[Test]
    public function more_indented_plain_continuation_folds_into_space(): void
    {
        self::assertSame(['a' => 'one two'], Yaml::parse("a: one\n     two"));
        self::assertSame(['b' => ['one two']], Yaml::parse("b:\n  - one\n     two"));
    }

    #[Test]
    public function sequence_own_line_collection_tag_resolves_onto_entry_mapping(): void
    {
        self::assertSame([['a' => 1, 'b' => 2]], Yaml::parse("- !!map\n  a: 1\n  b: 2"));
    }

    #[Test]
    public function sequence_own_line_local_tag_wraps_entry_mapping_in_tagged_value(): void
    {
        $result = Yaml::parse("- !custom\n  a: 1");

        self::assertInstanceOf(TaggedValue::class, $result[0]);
        self::assertSame('!custom', $result[0]->tag);
        self::assertSame(['a' => 1], $result[0]->value);
    }

    #[Test]
    public function nested_sequence_own_line_collection_tag_resolves_onto_entry_mapping(): void
    {
        self::assertSame(['root' => [['b' => 1]]], Yaml::parse("root:\n  - !!map\n    b: 1"));
    }

    #[Test]
    public function sequence_same_line_anchor_with_sibling_keys_keeps_line_indent_baseline(): void
    {
        self::assertSame([['a' => 1, 'b' => 2]], Yaml::parse("- \n  &m a: 1\n  b: 2"));
        self::assertSame(
            [['a' => 1, 'b' => 2], ['a' => 1, 'b' => 2]],
            Yaml::parse("- \n  &m a: 1\n  b: 2\n- *m"),
        );
    }

    #[Test]
    public function root_own_line_local_tag_wraps_document_in_tagged_value(): void
    {
        $result = Yaml::parse("!custom\na: 1");

        self::assertInstanceOf(TaggedValue::class, $result);
        self::assertSame('!custom', $result->tag);
        self::assertSame(['a' => 1], $result->value);
    }

    #[Test]
    public function sequence_entry_own_line_attributes_keep_control_semantics(): void
    {
        self::assertSame([['a' => 1]], Yaml::parse("- &m\n  a: 1"));
        self::assertSame([[1]], Yaml::parse("- !!seq\n  - 1"));
    }
}
