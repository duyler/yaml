<?php

declare(strict_types=1);

namespace Duyler\Yaml\Test\Unit\Parser;

use Duyler\Yaml\Exception\DepthLimitExceededException;
use Duyler\Yaml\Exception\DirectiveException;
use Duyler\Yaml\Exception\ParseException;
use Duyler\Yaml\Exception\SyntaxException;
use Duyler\Yaml\Exception\UnsupportedFeatureException;
use Duyler\Yaml\Internal\Node\AliasNode;
use Duyler\Yaml\Internal\Node\DocumentNode;
use Duyler\Yaml\Internal\Node\MappingNode;
use Duyler\Yaml\Internal\Node\Node;
use Duyler\Yaml\Internal\Node\ScalarNode;
use Duyler\Yaml\Internal\Node\SequenceNode;
use Duyler\Yaml\Internal\Parser\Limits;
use Duyler\Yaml\Internal\Parser\ParseContext;
use Duyler\Yaml\Internal\Parser\Parser;
use Duyler\Yaml\Internal\Scanner\Position;
use Duyler\Yaml\Internal\Scanner\ScalarStyle;
use Duyler\Yaml\Internal\Scanner\Scanner;
use Duyler\Yaml\Internal\Scanner\Token;
use Duyler\Yaml\Internal\Scanner\TokenType;
use Duyler\Yaml\Yaml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use LogicException;

use function array_map;
use function str_repeat;

class ParserTest extends TestCase
{
    #[Test]
    #[DataProvider('parse_trees')]
    public function input_parses_into_expected_tree(string $input, array $expected): void
    {
        $documents = $this->parse($input);

        self::assertSame($expected, self::dumpDocuments($documents));
    }

    public static function parse_trees(): array
    {
        return [
            'empty_input' => ['', []],
            'root_plain_scalar' => [
                'plain',
                [['doc', 1, 1, ['scalar', 'plain', 'Plain', 1, 1, null, null, null, null]]],
            ],
            'simple_mapping' => [
                'a: 1',
                [['doc', 1, 1, ['map', 1, 1, null, null,
                    ['scalar', 'a', 'Plain', 1, 1, null, null, null, null],
                    ['scalar', '1', 'Plain', 1, 4, null, null, null, null]]]],
            ],
            'mapping_two_pairs' => [
                "a: 1\nb: 2",
                [['doc', 1, 1, ['map', 1, 1, null, null,
                    ['scalar', 'a', 'Plain', 1, 1, null, null, null, null],
                    ['scalar', '1', 'Plain', 1, 4, null, null, null, null],
                    ['scalar', 'b', 'Plain', 2, 1, null, null, null, null],
                    ['scalar', '2', 'Plain', 2, 4, null, null, null, null]]]],
            ],
            'nested_mapping' => [
                "a:\n  b: 2",
                [['doc', 1, 1, ['map', 1, 1, null, null,
                    ['scalar', 'a', 'Plain', 1, 1, null, null, null, null],
                    ['map', 2, 3, null, null,
                        ['scalar', 'b', 'Plain', 2, 3, null, null, null, null],
                        ['scalar', '2', 'Plain', 2, 6, null, null, null, null]]]]],
            ],
            'deeply_nested_mapping' => [
                "a:\n  b:\n    c: 3",
                [['doc', 1, 1, ['map', 1, 1, null, null,
                    ['scalar', 'a', 'Plain', 1, 1, null, null, null, null],
                    ['map', 2, 3, null, null,
                        ['scalar', 'b', 'Plain', 2, 3, null, null, null, null],
                        ['map', 3, 5, null, null,
                            ['scalar', 'c', 'Plain', 3, 5, null, null, null, null],
                            ['scalar', '3', 'Plain', 3, 8, null, null, null, null]]]]]],
            ],
            'root_sequence' => [
                "- 1\n- 2",
                [['doc', 1, 1, ['seq', 1, 1, null, null,
                    ['scalar', '1', 'Plain', 1, 3, null, null, null, null],
                    ['scalar', '2', 'Plain', 2, 3, null, null, null, null]]]],
            ],
            'nested_sequence_in_mapping' => [
                "a:\n  - 1\n  - 2",
                [['doc', 1, 1, ['map', 1, 1, null, null,
                    ['scalar', 'a', 'Plain', 1, 1, null, null, null, null],
                    ['seq', 2, 3, null, null,
                        ['scalar', '1', 'Plain', 2, 5, null, null, null, null],
                        ['scalar', '2', 'Plain', 3, 5, null, null, null, null]]]]],
            ],
            'indentless_sequence' => [
                "a:\n- 1\n- 2\nb: 3",
                [['doc', 1, 1, ['map', 1, 1, null, null,
                    ['scalar', 'a', 'Plain', 1, 1, null, null, null, null],
                    ['seq', 2, 1, null, null,
                        ['scalar', '1', 'Plain', 2, 3, null, null, null, null],
                        ['scalar', '2', 'Plain', 3, 3, null, null, null, null]],
                    ['scalar', 'b', 'Plain', 4, 1, null, null, null, null],
                    ['scalar', '3', 'Plain', 4, 4, null, null, null, null]]]],
            ],
            'null_mapping_value_before_sibling' => [
                "a:\nb: 1",
                [['doc', 1, 1, ['map', 1, 1, null, null,
                    ['scalar', 'a', 'Plain', 1, 1, null, null, null, null],
                    ['scalar', '', 'Plain', 1, 2, null, null, null, null],
                    ['scalar', 'b', 'Plain', 2, 1, null, null, null, null],
                    ['scalar', '1', 'Plain', 2, 4, null, null, null, null]]]],
            ],
            'null_mapping_value_at_eof' => [
                'a:',
                [['doc', 1, 1, ['map', 1, 1, null, null,
                    ['scalar', 'a', 'Plain', 1, 1, null, null, null, null],
                    ['scalar', '', 'Plain', 1, 2, null, null, null, null]]]],
            ],
            'null_sequence_entry' => [
                "- \n- x",
                [['doc', 1, 1, ['seq', 1, 1, null, null,
                    ['scalar', '', 'Plain', 1, 1, null, null, null, null],
                    ['scalar', 'x', 'Plain', 2, 3, null, null, null, null]]]],
            ],
            'value_on_next_line' => [
                "a:\n  x",
                [['doc', 1, 1, ['map', 1, 1, null, null,
                    ['scalar', 'a', 'Plain', 1, 1, null, null, null, null],
                    ['scalar', 'x', 'Plain', 2, 3, null, null, null, null]]]],
            ],
            'block_scalar_value' => [
                "a: |\n  x",
                [['doc', 1, 1, ['map', 1, 1, null, null,
                    ['scalar', 'a', 'Plain', 1, 1, null, null, null, null],
                    ['scalar', 'x', 'Literal', 1, 4, null, null, 'Clip', null]]]],
            ],
            'block_scalar_in_sequence_entry' => [
                "- |\n  x",
                [['doc', 1, 1, ['seq', 1, 1, null, null,
                    ['scalar', 'x', 'Literal', 1, 3, null, null, 'Clip', null]]]],
            ],
            'flow_sequence' => [
                '[1, 2]',
                [['doc', 1, 1, ['seq', 1, 1, null, null,
                    ['scalar', '1', 'Plain', 1, 2, null, null, null, null],
                    ['scalar', '2', 'Plain', 1, 5, null, null, null, null]]]],
            ],
            'flow_mapping' => [
                '{a: 1, b: 2}',
                [['doc', 1, 1, ['map', 1, 1, null, null,
                    ['scalar', 'a', 'Plain', 1, 2, null, null, null, null],
                    ['scalar', '1', 'Plain', 1, 5, null, null, null, null],
                    ['scalar', 'b', 'Plain', 1, 8, null, null, null, null],
                    ['scalar', '2', 'Plain', 1, 11, null, null, null, null]]]],
            ],
            'empty_flow_sequence' => [
                '[]',
                [['doc', 1, 1, ['seq', 1, 1, null, null]]],
            ],
            'empty_flow_mapping' => [
                '{}',
                [['doc', 1, 1, ['map', 1, 1, null, null]]],
            ],
            'nested_flow_collections' => [
                '{a: [1, {b: 2}]}',
                [['doc', 1, 1, ['map', 1, 1, null, null,
                    ['scalar', 'a', 'Plain', 1, 2, null, null, null, null],
                    ['seq', 1, 5, null, null,
                        ['scalar', '1', 'Plain', 1, 6, null, null, null, null],
                        ['map', 1, 9, null, null,
                            ['scalar', 'b', 'Plain', 1, 10, null, null, null, null],
                            ['scalar', '2', 'Plain', 1, 13, null, null, null, null]]]]]],
            ],
            'flow_single_pair_sequence_is_mapping_entry' => [
                '[a: 1]',
                [['doc', 1, 1, ['seq', 1, 1, null, null,
                    ['map', 1, 2, null, null,
                        ['scalar', 'a', 'Plain', 1, 2, null, null, null, null],
                        ['scalar', '1', 'Plain', 1, 5, null, null, null, null]]]]],
            ],
            'flow_in_mapping_value_on_next_line' => [
                "a:\n  [1]",
                [['doc', 1, 1, ['map', 1, 1, null, null,
                    ['scalar', 'a', 'Plain', 1, 1, null, null, null, null],
                    ['seq', 2, 3, null, null,
                        ['scalar', '1', 'Plain', 2, 4, null, null, null, null]]]]],
            ],
            'multi_doc_with_markers' => [
                "---\na: 1\n---\nb: 2",
                [
                    ['doc', 1, 1, ['map', 2, 1, null, null,
                        ['scalar', 'a', 'Plain', 2, 1, null, null, null, null],
                        ['scalar', '1', 'Plain', 2, 4, null, null, null, null]]],
                    ['doc', 3, 1, ['map', 4, 1, null, null,
                        ['scalar', 'b', 'Plain', 4, 1, null, null, null, null],
                        ['scalar', '2', 'Plain', 4, 4, null, null, null, null]]],
                ],
            ],
            'multi_doc_first_document_without_marker' => [
                "a: 1\n---\nb: 2",
                [
                    ['doc', 1, 1, ['map', 1, 1, null, null,
                        ['scalar', 'a', 'Plain', 1, 1, null, null, null, null],
                        ['scalar', '1', 'Plain', 1, 4, null, null, null, null]]],
                    ['doc', 2, 1, ['map', 3, 1, null, null,
                        ['scalar', 'b', 'Plain', 3, 1, null, null, null, null],
                        ['scalar', '2', 'Plain', 3, 4, null, null, null, null]]],
                ],
            ],
            'null_documents_between_markers' => [
                "---\n---\n",
                [
                    ['doc', 1, 1, ['scalar', '', 'Plain', 2, 1, null, null, null, null]],
                    ['doc', 2, 1, ['scalar', '', 'Plain', 2, 1, null, null, null, null]],
                ],
            ],
            'document_end_marker_closes_document' => [
                "a: 1\n...\n---\nb: 2",
                [
                    ['doc', 1, 1, ['map', 1, 1, null, null,
                        ['scalar', 'a', 'Plain', 1, 1, null, null, null, null],
                        ['scalar', '1', 'Plain', 1, 4, null, null, null, null]]],
                    ['doc', 3, 1, ['map', 4, 1, null, null,
                        ['scalar', 'b', 'Plain', 4, 1, null, null, null, null],
                        ['scalar', '2', 'Plain', 4, 4, null, null, null, null]]],
                ],
            ],
            'alias_value' => [
                'a: *x',
                [['doc', 1, 1, ['map', 1, 1, null, null,
                    ['scalar', 'a', 'Plain', 1, 1, null, null, null, null],
                    ['alias', 'x', 1, 4]]]],
            ],
            'tagged_scalar' => [
                '!tag x',
                [['doc', 1, 1, ['scalar', 'x', 'Plain', 1, 6, '!tag', null, null, null]]],
            ],
            'double_bang_tag_scalar' => [
                '!!str x',
                [['doc', 1, 1, ['scalar', 'x', 'Plain', 1, 7, 'tag:yaml.org,2002:str', null, null, null]]],
            ],
            'verbatim_tag_scalar' => [
                '!<tag.verb> x',
                [['doc', 1, 1, ['scalar', 'x', 'Plain', 1, 13, '!<tag.verb>', null, null, null]]],
            ],
            'anchored_scalar' => [
                '&a x',
                [['doc', 1, 1, ['scalar', 'x', 'Plain', 1, 4, null, 'a', null, null]]],
            ],
            'anchor_and_tag_on_sequence' => [
                "&seq !tag\n- 1",
                [['doc', 1, 1, ['seq', 2, 1, '!tag', 'seq',
                    ['scalar', '1', 'Plain', 2, 3, null, null, null, null]]]],
            ],
            'quoted_mapping_key_keeps_verbatim_raw' => [
                '"k": 1',
                [['doc', 1, 1, ['map', 1, 1, null, null,
                    ['scalar', '"k"', 'DoubleQuoted', 1, 1, null, null, null, null],
                    ['scalar', '1', 'Plain', 1, 6, null, null, null, null]]]],
            ],
            'directive_tokens_are_skipped_structurally' => [
                "%YAML 1.2\n%TAG !e! tag:example.com\n---\na: 1",
                [['doc', 3, 1, ['map', 4, 1, null, null,
                    ['scalar', 'a', 'Plain', 4, 1, null, null, null, null],
                    ['scalar', '1', 'Plain', 4, 4, null, null, null, null]]]],
            ],
            'document_end_marker_as_first_token_yields_no_documents' => [
                '...',
                [],
            ],
            'double_document_end_markers_after_root' => [
                "a: 1\n...\n...\n",
                [['doc', 1, 1, ['map', 1, 1, null, null,
                    ['scalar', 'a', 'Plain', 1, 1, null, null, null, null],
                    ['scalar', '1', 'Plain', 1, 4, null, null, null, null]]]],
            ],
            'empty_sequence_entry_at_eof' => [
                "- \n",
                [['doc', 1, 1, ['seq', 1, 1, null, null,
                    ['scalar', '', 'Plain', 1, 1, null, null, null, null]]]],
            ],
            'alias_inside_flow_sequence' => [
                '[*x]',
                [['doc', 1, 1, ['seq', 1, 1, null, null,
                    ['alias', 'x', 1, 2]]]],
            ],
            'empty_mapping_value_in_flow_mapping' => [
                '{a:}',
                [['doc', 1, 1, ['map', 1, 1, null, null,
                    ['scalar', 'a', 'Plain', 1, 2, null, null, null, null],
                    ['scalar', '', 'Plain', 1, 3, null, null, null, null]]]],
            ],
            'nested_mapping_after_sequence_entry_break' => [
                "- \n  k: v",
                [['doc', 1, 1, ['seq', 1, 1, null, null,
                    ['map', 2, 3, null, null,
                        ['scalar', 'k', 'Plain', 2, 3, null, null, null, null],
                        ['scalar', 'v', 'Plain', 2, 6, null, null, null, null]]]]],
            ],
            'nested_sequence_after_sequence_entry_break' => [
                "- \n  - y",
                [['doc', 1, 1, ['seq', 1, 1, null, null,
                    ['seq', 2, 3, null, null,
                        ['scalar', 'y', 'Plain', 2, 5, null, null, null, null]]]]],
            ],
            'sibling_entry_after_nested_sequence_value' => [
                "- \n  - y\n- z",
                [['doc', 1, 1, ['seq', 1, 1, null, null,
                    ['seq', 2, 3, null, null,
                        ['scalar', 'y', 'Plain', 2, 5, null, null, null, null]],
                    ['scalar', 'z', 'Plain', 3, 3, null, null, null, null]]]],
            ],
        ];
    }

    #[Test]
    #[DataProvider('parse_errors')]
    public function invalid_input_throws_typed_exception_with_coordinates(
        string $input,
        string $exceptionClass,
        string $message,
        int $line,
        int $column,
    ): void {
        $this->expectException($exceptionClass);

        try {
            $this->parse($input);
            self::fail($exceptionClass . ' expected');
        } catch (ParseException $exception) {
            self::assertSame($line . ':' . $column . ': ' . $message, $exception->getMessage());
            self::assertSame($line, $exception->lineNumber);
            self::assertSame($column, $exception->column);

            throw $exception;
        }
    }

    public static function parse_errors(): array
    {
        return [
            'compact_sequence_entries' => [
                '- - a',
                UnsupportedFeatureException::class,
                'Compact nested collections are not supported',
                1,
                1,
            ],
            'compact_mapping_in_sequence_entry' => [
                '- k: v',
                UnsupportedFeatureException::class,
                'Compact nested collections are not supported',
                1,
                1,
            ],
            'compact_mapping_in_second_entry' => [
                "- x\n- y: 1",
                UnsupportedFeatureException::class,
                'Compact nested collections are not supported',
                2,
                1,
            ],
            'explicit_complex_key' => [
                '? key',
                SyntaxException::class,
                'Explicit complex keys are not supported',
                1,
                1,
            ],
            'explicit_complex_key_as_value' => [
                'a: ? key',
                SyntaxException::class,
                'Explicit complex keys are not supported',
                1,
                4,
            ],
            'sequence_entry_on_mapping_value_line' => [
                'a: - x',
                SyntaxException::class,
                'Sequence entry is not allowed on a mapping value line',
                1,
                4,
            ],
            'double_trailing_comma_in_flow_sequence' => [
                '[1,,]',
                SyntaxException::class,
                'Trailing comma in flow collection',
                1,
                3,
            ],
            'empty_flow_entry_in_flow_sequence' => [
                '[1,,2]',
                SyntaxException::class,
                'Trailing comma in flow collection',
                1,
                3,
            ],
            'double_trailing_comma_in_flow_mapping' => [
                '{a: 1,,}',
                SyntaxException::class,
                'Trailing comma in flow collection',
                1,
                6,
            ],
            'missing_comma_in_flow_sequence' => [
                '[1 "x"]',
                SyntaxException::class,
                'Expected flow entry separator or collection end',
                1,
                4,
            ],
            'missing_mapping_value_in_flow_mapping' => [
                '{a "1"}',
                SyntaxException::class,
                'Expected mapping value after flow mapping key',
                1,
                4,
            ],
            'invalid_sequence_entry_indentation' => [
                "- a\n  - b",
                SyntaxException::class,
                'Invalid sequence entry indentation',
                2,
                3,
            ],
            'content_after_document_end_marker' => [
                "a: 1\n...\nb: 2",
                SyntaxException::class,
                'Content after document end marker must be preceded by a document start marker',
                3,
                1,
            ],
            'content_after_root_node' => [
                "- a\nb: 1",
                SyntaxException::class,
                'Unexpected content after document root node',
                2,
                1,
            ],
            'unexpectedly_indented_root_mapping' => [
                "a: 1\n  b: 2",
                SyntaxException::class,
                'Unexpected content after document root node',
                2,
                3,
            ],
            'directive_after_root_node' => [
                "a: 1\n%YAML 1.2",
                DirectiveException::class,
                'Directive must not appear after document content',
                2,
                1,
            ],
            'directive_between_document_end_and_eof' => [
                "a: 1\n...\n%YAML 1.2\n",
                DirectiveException::class,
                'Directives must be followed by a document start marker',
                3,
                1,
            ],
            'plain_scalar_after_mapping_key_line' => [
                "a: 1\nplain",
                SyntaxException::class,
                'Unexpected content after document root node',
                2,
                1,
            ],
            'null_value_before_lower_indented_content' => [
                "a:\nx",
                SyntaxException::class,
                'Unexpected content after document root node',
                2,
                1,
            ],
            'explicit_complex_key_inside_sequence_entry' => [
                '- ? k',
                SyntaxException::class,
                'Explicit complex keys are not supported',
                1,
                3,
            ],
            'null_sequence_entry_before_lower_indented_plain' => [
                "- \nz",
                SyntaxException::class,
                'Unexpected content after document root node',
                2,
                1,
            ],
            'null_value_before_lower_indented_sibling_entry' => [
                "x:\n  b:\n- z",
                SyntaxException::class,
                'Unexpected content after document root node',
                3,
                1,
            ],
        ];
    }

    #[Test]
    public function depth_limit_is_enforced_before_recursion(): void
    {
        $tokens = new Scanner()->scan(str_repeat('[', 256) . 'x' . str_repeat(']', 256));
        $parser = new Parser();

        $this->expectException(DepthLimitExceededException::class);

        try {
            $parser->parse($tokens, $this->context());
            self::fail('DepthLimitExceededException expected');
        } catch (DepthLimitExceededException $exception) {
            self::assertSame(1, $exception->lineNumber);
            self::assertSame(257, $exception->column);

            throw $exception;
        }
    }

    #[Test]
    public function depth_at_exact_limit_parses(): void
    {
        $tokens = new Scanner()->scan(str_repeat('[', 255) . 'x' . str_repeat(']', 255));
        $documents = new Parser()->parse($tokens, $this->context());

        self::assertCount(1, $documents);
        $root = $documents[0]->root;

        for ($i = 0; $i < 255; $i++) {
            self::assertInstanceOf(SequenceNode::class, $root);
            $root = $root->items[0];
        }

        self::assertInstanceOf(ScalarNode::class, $root);
        self::assertSame('x', $root->raw);
    }

    #[Test]
    public function depth_limit_uses_context_limits(): void
    {
        $tokens = new Scanner()->scan(str_repeat('[', 2) . 'x' . str_repeat(']', 2));
        $parser = new Parser();

        $this->expectException(DepthLimitExceededException::class);

        $parser->parse($tokens, new ParseContext(file: null, limits: new Limits(maxDepth: 2, maxAliases: 512)));
    }

    #[Test]
    public function depth_limit_exception_extends_parse_contract(): void
    {
        $exception = new DepthLimitExceededException(
            message: 'Maximum node depth exceeded',
            lineNumber: 2,
            column: 3,
        );

        self::assertInstanceOf(ParseException::class, $exception);
        self::assertSame('2:3: Maximum node depth exceeded', $exception->getMessage());
    }

    #[Test]
    public function tokens_without_mapping_value_in_flow_reach_parser_error(): void
    {
        $tokens = [
            new Token(
                type: TokenType::FlowMappingStart,
                value: '{',
                position: new Position(1, 1),
            ),
            new Token(
                type: TokenType::Scalar,
                value: 'a',
                position: new Position(1, 2),
                style: ScalarStyle::Plain,
            ),
        ];
        $parser = new Parser();

        $this->expectException(SyntaxException::class);

        try {
            $parser->parse($tokens, $this->context());
            self::fail('SyntaxException expected');
        } catch (SyntaxException $exception) {
            self::assertSame('1:3: Unterminated flow collection', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    #[DataProvider('raw_token_errors')]
    public function raw_token_streams_reach_parser_defenses(array $tokens, string $message, int $line, int $column): void
    {
        $parser = new Parser();

        $this->expectException(SyntaxException::class);

        try {
            $parser->parse($tokens, $this->context());
            self::fail('SyntaxException expected');
        } catch (SyntaxException $exception) {
            self::assertSame($line . ':' . $column . ': ' . $message, $exception->getMessage());

            throw $exception;
        }
    }

    public static function raw_token_errors(): array
    {
        return [
            'flow_mapping_unterminated_after_key' => [
                [
                    self::token(TokenType::FlowMappingStart, '{', 1, 1),
                    self::token(TokenType::Scalar, 'a', 1, 2),
                ],
                'Unterminated flow collection',
                1,
                3,
            ],
            'flow_mapping_unterminated_after_value' => [
                [
                    self::token(TokenType::FlowMappingStart, '{', 1, 1),
                    self::token(TokenType::Scalar, 'a', 1, 2),
                    self::token(TokenType::MappingValue, ':', 1, 3),
                    self::token(TokenType::Scalar, '1', 1, 4),
                ],
                'Unterminated flow collection',
                1,
                5,
            ],
            'flow_mapping_trailing_comma_at_eof' => [
                [
                    self::token(TokenType::FlowMappingStart, '{', 1, 1),
                    self::token(TokenType::Scalar, 'a', 1, 2),
                    self::token(TokenType::MappingValue, ':', 1, 3),
                    self::token(TokenType::Scalar, '1', 1, 4),
                    self::token(TokenType::FlowEntry, ',', 1, 5),
                ],
                'Trailing comma in flow collection',
                1,
                5,
            ],
            'flow_mapping_unexpected_token_after_value' => [
                [
                    self::token(TokenType::FlowMappingStart, '{', 1, 1),
                    self::token(TokenType::Scalar, 'a', 1, 2),
                    self::token(TokenType::MappingValue, ':', 1, 3),
                    self::token(TokenType::Scalar, '1', 1, 4),
                    self::token(TokenType::Scalar, 'b', 1, 5),
                ],
                'Expected flow entry separator or collection end',
                1,
                5,
            ],
            'flow_sequence_unterminated_after_item' => [
                [
                    self::token(TokenType::FlowSequenceStart, '[', 1, 1),
                    self::token(TokenType::Scalar, '1', 1, 2),
                ],
                'Unterminated flow collection',
                1,
                3,
            ],
            'flow_sequence_leading_comma' => [
                [
                    self::token(TokenType::FlowSequenceStart, '[', 1, 1),
                    self::token(TokenType::FlowEntry, ',', 1, 2),
                ],
                'Trailing comma in flow collection',
                1,
                2,
            ],
            'flow_mapping_unterminated_alone' => [
                [
                    self::token(TokenType::FlowMappingStart, '{', 1, 1),
                ],
                'Unterminated flow collection',
                1,
                2,
            ],
            'flow_sequence_unterminated_alone' => [
                [
                    self::token(TokenType::FlowSequenceStart, '[', 1, 1),
                ],
                'Unterminated flow collection',
                1,
                2,
            ],
            'flow_sequence_trailing_comma_at_eof' => [
                [
                    self::token(TokenType::FlowSequenceStart, '[', 1, 1),
                    self::token(TokenType::Scalar, '1', 1, 2),
                    self::token(TokenType::FlowEntry, ',', 1, 3),
                ],
                'Trailing comma in flow collection',
                1,
                3,
            ],
            'flow_mapping_leading_comma' => [
                [
                    self::token(TokenType::FlowMappingStart, '{', 1, 1),
                    self::token(TokenType::FlowEntry, ',', 1, 2),
                ],
                'Trailing comma in flow collection',
                1,
                2,
            ],
        ];
    }

    #[Test]
    public function trailing_comma_in_flow_collections_is_valid_per_yaml_1_2_2(): void
    {
        self::assertSame([[1, 2]], Yaml::parseAll('[ 1, 2, ]'));
        self::assertSame([['a' => 1, 'b' => 2]], Yaml::parseAll('{ a: 1, b: 2, }'));
    }

    private static function token(TokenType $type, string $value, int $line, int $column): Token
    {
        return new Token(type: $type, value: $value, position: new Position(line: $line, column: $column));
    }

    private function parse(string $input): array
    {
        $tokens = new Scanner()->scan($input);

        return new Parser()->parse($tokens, $this->context());
    }

    private function context(): ParseContext
    {
        return new ParseContext(file: null, limits: new Limits());
    }

    private static function dumpDocuments(array $documents): array
    {
        return array_map(static fn(DocumentNode $document): array => [
            'doc',
            $document->position->line,
            $document->position->column,
            self::dumpNode($document->root),
        ], $documents);
    }

    private static function dumpNode(Node $node): array
    {
        if ($node instanceof ScalarNode) {
            return [
                'scalar',
                $node->raw,
                $node->style->name,
                $node->position->line,
                $node->position->column,
                $node->tag,
                $node->anchor,
                $node->blockHeader?->chomping->name,
                $node->blockHeader?->explicitIndent,
            ];
        }
        if ($node instanceof SequenceNode) {
            return [
                'seq',
                $node->position->line,
                $node->position->column,
                $node->tag,
                $node->anchor,
                ...array_map(static fn(Node $item): array => self::dumpNode($item), $node->items),
            ];
        }
        if ($node instanceof MappingNode) {
            $dump = ['map', $node->position->line, $node->position->column, $node->tag, $node->anchor];
            foreach ($node->entries as $entry) {
                $dump[] = self::dumpNode($entry->key);
                $dump[] = self::dumpNode($entry->value);
            }

            return $dump;
        }
        if ($node instanceof AliasNode) {
            return ['alias', $node->name, $node->position->line, $node->position->column];
        }

        throw new LogicException('Unknown node type');
    }
}
