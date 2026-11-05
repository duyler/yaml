<?php

declare(strict_types=1);

namespace Duyler\Yaml\Test\Unit\Scanner;

use Duyler\Yaml\Exception\SyntaxException;
use Duyler\Yaml\Exception\UnsupportedFeatureException;
use Duyler\Yaml\Internal\Scanner\Scanner;
use Duyler\Yaml\Internal\Scanner\ScalarStyle;
use Duyler\Yaml\Internal\Scanner\Token;
use Duyler\Yaml\Internal\Scanner\TokenType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;

class ScannerTest extends TestCase
{
    #[Test]
    #[DataProvider('token_streams')]
    public function input_is_tokenized_with_exact_positions(string $input, array $expected): void
    {
        $scanner = new Scanner();

        $actual = [];
        foreach ($scanner->scan($input) as $token) {
            $actual[] = [$token->type->name, $token->value, $token->position->line, $token->position->column];
        }

        self::assertSame($expected, $actual);
    }

    public static function token_streams(): array
    {
        return [
            'empty_input' => ['', []],
            'comment_only' => ['# only comment', []],
            'plain_scalar' => ['x', [['Scalar', 'x', 1, 1]]],
            'plain_scalar_with_trailing_spaces' => ['x   ', [['Scalar', 'x', 1, 1]]],
            'plain_scalar_cannot_be_split_by_hash_without_blank' => ['a#b', [['Scalar', 'a#b', 1, 1]]],
            'plain_scalar_cannot_be_split_by_colon_without_blank' => ['a:b', [['Scalar', 'a:b', 1, 1]]],
            'plain_scalar_with_question_mark_mid_word' => ['a?b', [['Scalar', 'a?b', 1, 1]]],
            'plain_scalar_with_dash_mid_word' => ['a-b', [['Scalar', 'a-b', 1, 1]]],
            'plain_scalar_starting_with_dash_word' => ['-abc', [['Scalar', '-abc', 1, 1]]],
            'plain_scalar_starting_with_document_marker_like_text' => ['---x', [['Scalar', '---x', 1, 1]]],
            'mapping_on_one_line' => [
                'key: value',
                [['Scalar', 'key', 1, 1], ['MappingValue', ':', 1, 4], ['Scalar', 'value', 1, 6]],
            ],
            'mapping_value_at_end_of_input' => [
                'a:',
                [['Scalar', 'a', 1, 1], ['MappingValue', ':', 1, 2]],
            ],
            'nested_mapping_on_next_line' => [
                "a:\n  b: c",
                [['Scalar', 'a', 1, 1], ['MappingValue', ':', 1, 2], ['Scalar', 'b', 2, 3], ['MappingValue', ':', 2, 4], ['Scalar', 'c', 2, 6]],
            ],
            'sequence_entries' => [
                "- a\n- b",
                [['SequenceEntry', '-', 1, 1], ['Scalar', 'a', 1, 3], ['SequenceEntry', '-', 2, 1], ['Scalar', 'b', 2, 3]],
            ],
            'sequence_entry_with_inline_mapping' => [
                '- k: v',
                [['SequenceEntry', '-', 1, 1], ['Scalar', 'k', 1, 3], ['MappingValue', ':', 1, 4], ['Scalar', 'v', 1, 6]],
            ],
            'sequence_entry_without_value' => [
                '-',
                [['SequenceEntry', '-', 1, 1]],
            ],
            'compact_sequence_entries_tokenized_in_one_line' => [
                '- - a',
                [['SequenceEntry', '-', 1, 1], ['SequenceEntry', '-', 1, 3], ['Scalar', 'a', 1, 5]],
            ],
            'compact_mapping_inside_sequence' => [
                "- a: b\n  c: d",
                [
                    ['SequenceEntry', '-', 1, 1], ['Scalar', 'a', 1, 3], ['MappingValue', ':', 1, 4],
                    ['Scalar', 'b', 1, 6], ['Scalar', 'c', 2, 3], ['MappingValue', ':', 2, 4], ['Scalar', 'd', 2, 6],
                ],
            ],
            'flow_sequence_with_entries' => [
                '[a, b]',
                [['FlowSequenceStart', '[', 1, 1], ['Scalar', 'a', 1, 2], ['FlowEntry', ',', 1, 3], ['Scalar', 'b', 1, 5], ['FlowSequenceEnd', ']', 1, 6]],
            ],
            'flow_mapping_with_nested_flow_sequence' => [
                '[a, {b: c}]',
                [
                    ['FlowSequenceStart', '[', 1, 1], ['Scalar', 'a', 1, 2], ['FlowEntry', ',', 1, 3],
                    ['FlowMappingStart', '{', 1, 5], ['Scalar', 'b', 1, 6], ['MappingValue', ':', 1, 7],
                    ['Scalar', 'c', 1, 9], ['FlowMappingEnd', '}', 1, 10], ['FlowSequenceEnd', ']', 1, 11],
                ],
            ],
            'flow_colon_after_word_is_part_of_scalar' => [
                '{a:b}',
                [['FlowMappingStart', '{', 1, 1], ['Scalar', 'a:b', 1, 2], ['FlowMappingEnd', '}', 1, 5]],
            ],
            'flow_colon_before_flow_end_is_mapping_value' => [
                '{a:}',
                [['FlowMappingStart', '{', 1, 1], ['Scalar', 'a', 1, 2], ['MappingValue', ':', 1, 3], ['FlowMappingEnd', '}', 1, 4]],
            ],
            'deep_flow_nesting' => [
                '[[a]]',
                [['FlowSequenceStart', '[', 1, 1], ['FlowSequenceStart', '[', 1, 2], ['Scalar', 'a', 1, 3], ['FlowSequenceEnd', ']', 1, 4], ['FlowSequenceEnd', ']', 1, 5]],
            ],
            'comment_after_value_is_not_tokenized' => [
                'a: b # comment',
                [['Scalar', 'a', 1, 1], ['MappingValue', ':', 1, 2], ['Scalar', 'b', 1, 4]],
            ],
            'multiline_plain_scalar_folds_break_to_space' => [
                "a: first\n  second",
                [['Scalar', 'a', 1, 1], ['MappingValue', ':', 1, 2], ['Scalar', "first second", 1, 4]],
            ],
            'multiline_plain_scalar_stops_at_sibling_indent' => [
                "a: first\nb: x",
                [
                    ['Scalar', 'a', 1, 1], ['MappingValue', ':', 1, 2], ['Scalar', 'first', 1, 4],
                    ['Scalar', 'b', 2, 1], ['MappingValue', ':', 2, 2], ['Scalar', 'x', 2, 4],
                ],
            ],
            'multiline_plain_scalar_preserves_empty_line_break' => [
                "a: x\n\n  y",
                [['Scalar', 'a', 1, 1], ['MappingValue', ':', 1, 2], ['Scalar', "x\ny", 1, 4]],
            ],
            'multiline_plain_scalar_stops_before_sequence_entry_line' => [
                "a: x\n  - y",
                [['Scalar', 'a', 1, 1], ['MappingValue', ':', 1, 2], ['Scalar', 'x', 1, 4], ['SequenceEntry', '-', 2, 3], ['Scalar', 'y', 2, 5]],
            ],
            'multiline_plain_scalar_stops_before_comment_line' => [
                "a: x\n  # note\n  y",
                [['Scalar', 'a', 1, 1], ['MappingValue', ':', 1, 2], ['Scalar', 'x', 1, 4], ['Scalar', 'y', 3, 3]],
            ],
            'multiline_plain_scalar_stops_at_comment_between_lines' => [
                "a: x\n# top-level comment\nb: y",
                [
                    ['Scalar', 'a', 1, 1], ['MappingValue', ':', 1, 2], ['Scalar', 'x', 1, 4],
                    ['Scalar', 'b', 3, 1], ['MappingValue', ':', 3, 2], ['Scalar', 'y', 3, 4],
                ],
            ],
            'sequence_entry_scalar_folds_continuation_at_same_indent' => [
                "- one\n  two",
                [['SequenceEntry', '-', 1, 1], ['Scalar', "one two", 1, 3]],
            ],
            'sequence_entries_split_by_sibling_dash' => [
                "- one\n- two",
                [['SequenceEntry', '-', 1, 1], ['Scalar', 'one', 1, 3], ['SequenceEntry', '-', 2, 1], ['Scalar', 'two', 2, 3]],
            ],
            'multiline_plain_scalar_skips_trailing_blank_lines_at_eof' => [
                "a: x\n  ",
                [['Scalar', 'a', 1, 1], ['MappingValue', ':', 1, 2], ['Scalar', 'x', 1, 4]],
            ],
            'crlf_line_endings_strip_trailing_carriage_return' => [
                "a: x\r\nb: y",
                [
                    ['Scalar', 'a', 1, 1], ['MappingValue', ':', 1, 2], ['Scalar', 'x', 1, 4],
                    ['Scalar', 'b', 2, 1], ['MappingValue', ':', 2, 2], ['Scalar', 'y', 2, 4],
                ],
            ],
            'crlf_break_folds_like_lf' => [
                "a: x\r\n  y",
                [['Scalar', 'a', 1, 1], ['MappingValue', ':', 1, 2], ['Scalar', "x y", 1, 4]],
            ],
            'utf8_key_column_counts_bytes' => [
                'ключ: значение',
                [['Scalar', 'ключ', 1, 1], ['MappingValue', ':', 1, 9], ['Scalar', 'значение', 1, 11]],
            ],
            'utf8_scalar_column_counts_bytes' => [
                'a: é',
                [['Scalar', 'a', 1, 1], ['MappingValue', ':', 1, 2], ['Scalar', 'é', 1, 4]],
            ],
            'single_quoted_scalar_keeps_escaped_quote_verbatim' => [
                "'a ''b'' c'",
                [['Scalar', "'a ''b'' c'", 1, 1]],
            ],
            'double_quoted_scalar_keeps_escapes_verbatim' => [
                '"x\"y z"',
                [['Scalar', '"x\"y z"', 1, 1]],
            ],
            'anchor_token_is_verbatim' => [
                '&anchor value',
                [['Anchor', '&anchor', 1, 1], ['Scalar', 'value', 1, 9]],
            ],
            'alias_token_is_verbatim' => [
                '*alias',
                [['Alias', '*alias', 1, 1]],
            ],
            'tag_with_handle_token_is_verbatim' => [
                '!!str tag',
                [['Tag', '!!str', 1, 1], ['Scalar', 'tag', 1, 7]],
            ],
            'tag_with_custom_handle_token_is_verbatim' => [
                '!e!tag x',
                [['Tag', '!e!tag', 1, 1], ['Scalar', 'x', 1, 8]],
            ],
            'verbatim_tag_token_is_verbatim' => [
                '!<tag.verb> x',
                [['Tag', '!<tag.verb>', 1, 1], ['Scalar', 'x', 1, 13]],
            ],
            'bare_tag_token' => [
                '! x',
                [['Tag', '!', 1, 1], ['Scalar', 'x', 1, 3]],
            ],
            'anchor_inside_flow' => [
                '[&a x]',
                [['FlowSequenceStart', '[', 1, 1], ['Anchor', '&a', 1, 2], ['Scalar', 'x', 1, 5], ['FlowSequenceEnd', ']', 1, 6]],
            ],
            'alias_inside_flow' => [
                '[*a]',
                [['FlowSequenceStart', '[', 1, 1], ['Alias', '*a', 1, 2], ['FlowSequenceEnd', ']', 1, 4]],
            ],
            'literal_block_scalar_header_at_eof_yields_empty_scalar' => [
                'a: |',
                [['Scalar', 'a', 1, 1], ['MappingValue', ':', 1, 2], ['Scalar', '', 1, 4]],
            ],
            'literal_block_scalar_collects_indented_lines' => [
                "a: |\n  one\n  two",
                [['Scalar', 'a', 1, 1], ['MappingValue', ':', 1, 2], ['Scalar', "one\ntwo", 1, 4]],
            ],
            'folded_block_scalar_collects_indented_lines' => [
                "a: >\n  folded",
                [['Scalar', 'a', 1, 1], ['MappingValue', ':', 1, 2], ['Scalar', 'folded', 1, 4]],
            ],
            'block_scalar_strip_chomping_header' => [
                "a: |-\n  x\nb: 1",
                [
                    ['Scalar', 'a', 1, 1], ['MappingValue', ':', 1, 2], ['Scalar', 'x', 1, 4],
                    ['Scalar', 'b', 3, 1], ['MappingValue', ':', 3, 2], ['Scalar', '1', 3, 4],
                ],
            ],
            'block_scalar_keep_chomping_collects_trailing_blank_lines' => [
                "a: |+\n  x\n\n",
                [['Scalar', 'a', 1, 1], ['MappingValue', ':', 1, 2], ['Scalar', "x\n", 1, 4]],
            ],
            'block_scalar_explicit_indent_is_relative_to_parent' => [
                "a: |2\n   x",
                [['Scalar', 'a', 1, 1], ['MappingValue', ':', 1, 2], ['Scalar', ' x', 1, 4]],
            ],
            'block_scalar_stops_at_less_indented_sibling' => [
                "a: |\n  x\nb: 1",
                [
                    ['Scalar', 'a', 1, 1], ['MappingValue', ':', 1, 2], ['Scalar', 'x', 1, 4],
                    ['Scalar', 'b', 3, 1], ['MappingValue', ':', 3, 2], ['Scalar', '1', 3, 4],
                ],
            ],
            'block_scalar_with_trailing_comment_in_header' => [
                "a: | # note\n  x",
                [['Scalar', 'a', 1, 1], ['MappingValue', ':', 1, 2], ['Scalar', 'x', 1, 4]],
            ],
            'document_start_and_end_markers' => [
                "---\na: b\n...\n",
                [
                    ['DocumentStart', '---', 1, 1],
                    ['Scalar', 'a', 2, 1], ['MappingValue', ':', 2, 2], ['Scalar', 'b', 2, 4],
                    ['DocumentEnd', '...', 3, 1],
                ],
            ],
            'document_markers_at_eof' => [
                '---',
                [['DocumentStart', '---', 1, 1]],
            ],
            'directive_tokenizes_whole_line' => [
                "%YAML 1.2\n---\na: 1",
                [
                    ['Directive', '%YAML 1.2', 1, 1],
                    ['DocumentStart', '---', 2, 1],
                    ['Scalar', 'a', 3, 1], ['MappingValue', ':', 3, 2], ['Scalar', '1', 3, 4],
                ],
            ],
            'explicit_complex_key_indicator' => [
                "? key\n: value",
                [
                    ['ComplexKeyIndicator', '?', 1, 1], ['Scalar', 'key', 1, 3],
                    ['MappingValue', ':', 2, 1], ['Scalar', 'value', 2, 3],
                ],
            ],
            'flow_plain_scalar_is_single_line' => [
                "{a: x\n  y}",
                [
                    ['FlowMappingStart', '{', 1, 1], ['Scalar', 'a', 1, 2], ['MappingValue', ':', 1, 3],
                    ['Scalar', 'x', 1, 5], ['Scalar', 'y', 2, 3], ['FlowMappingEnd', '}', 2, 4],
                ],
            ],
            'indentation_only_input' => [
                '  ',
                [],
            ],
            'plain_scalar_eof_after_line_break' => [
                "a: x\n",
                [['Scalar', 'a', 1, 1], ['MappingValue', ':', 1, 2], ['Scalar', 'x', 1, 4]],
            ],
            'quoted_scalar_ends_plain_scalar_mid_word' => [
                'a"b"c',
                [['Scalar', 'a', 1, 1], ['Scalar', '"b"', 1, 2], ['Scalar', 'c', 1, 5]],
            ],
            'flow_open_bracket_inside_block_plain_is_content' => [
                'a[b',
                [['Scalar', 'a[b', 1, 1]],
            ],
            'question_mark_with_blank_ends_plain_scalar' => [
                'a ? b',
                [['Scalar', 'a', 1, 1], ['ComplexKeyIndicator', '?', 1, 3], ['Scalar', 'b', 1, 5]],
            ],
            'empty_block_scalar_before_sibling_mapping' => [
                "a: |\nb: 1",
                [
                    ['Scalar', 'a', 1, 1], ['MappingValue', ':', 1, 2], ['Scalar', '', 1, 4],
                    ['Scalar', 'b', 2, 1], ['MappingValue', ':', 2, 2], ['Scalar', '1', 2, 4],
                ],
            ],
            'block_scalar_blank_line_at_eof_without_break' => [
                "a: |\n  x\n  ",
                [['Scalar', 'a', 1, 1], ['MappingValue', ':', 1, 2], ['Scalar', "x\n", 1, 4]],
            ],
        ];
    }

    #[Test]
    #[DataProvider('syntax_errors')]
    public function invalid_input_throws_syntax_exception_with_coordinates(
        string $input,
        string $message,
        int $line,
        int $column,
    ): void {
        $scanner = new Scanner();

        $this->expectException(SyntaxException::class);

        try {
            $scanner->scan($input);
            self::fail('SyntaxException expected');
        } catch (SyntaxException $exception) {
            self::assertSame($line . ':' . $column . ': ' . $message, $exception->getMessage());
            self::assertSame($line, $exception->lineNumber);
            self::assertSame($column, $exception->column);
            self::assertNull($exception->fileName);

            throw $exception;
        }
    }

    public static function syntax_errors(): array
    {
        return [
            'tab_at_line_start' => ["\ta: b", 'Tab in indentation', 1, 1],
            'tab_in_indented_continuation' => ["a:\n\tb: c", 'Tab in indentation', 2, 1],
            'tab_in_plain_continuation_indent' => ["a: x\n\t y", 'Tab in indentation', 2, 1],
            'raw_control_character_in_scalar' => ["a\x01b", 'Raw control character is not allowed', 1, 2],
            'nul_byte_in_scalar' => ["a: x\x00y", 'Raw control character is not allowed', 1, 5],
            'raw_control_character_in_comment' => ["# note\x01", 'Raw control character is not allowed', 1, 7],
            'nul_byte_in_block_scalar_content' => ["a: |\n  x\x00y", 'Raw control character is not allowed', 2, 4],
            'control_byte_in_folded_scalar_content' => ["a: >\n  x\x01y", 'Raw control character is not allowed', 2, 4],
            'unterminated_flow_collection' => ['[a', 'Unterminated flow collection', 1, 3],
            'unterminated_flow_collection_after_value' => ["a: [x", 'Unterminated flow collection', 1, 6],
            'percent_outside_directive_position' => ['a: %x', "Character '%' cannot start a token outside the directive position", 1, 4],
            'percent_in_indented_position' => ["  %x", "Character '%' cannot start a token outside the directive position", 1, 3],
            'invalid_block_scalar_header_extra_text' => ['a: | x', 'Invalid block scalar header', 1, 6],
            'block_scalar_content_less_indented_than_explicit' => ["a: |2\n x", 'Block scalar content is not properly indented', 2, 2],
            'block_scalar_content_between_parent_and_content_indent' => ["a:\n  b: |2\n   x", 'Block scalar content is not properly indented', 3, 4],
            'empty_anchor_name' => ['& x', 'Anchor name is empty', 1, 2],
            'empty_alias_name' => ['* x', 'Alias name is empty', 1, 2],
            'unterminated_verbatim_tag' => ['!<abc', 'Unterminated verbatim tag', 1, 6],
        ];
    }

    #[Test]
    #[DataProvider('unsupported_features')]
    public function unsupported_construct_throws_with_opening_position(
        string $input,
        string $message,
        int $line,
        int $column,
    ): void {
        $scanner = new Scanner();

        $this->expectException(UnsupportedFeatureException::class);

        try {
            $scanner->scan($input);
            self::fail('UnsupportedFeatureException expected');
        } catch (UnsupportedFeatureException $exception) {
            self::assertSame($line . ':' . $column . ': ' . $message, $exception->getMessage());
            self::assertSame($line, $exception->lineNumber);
            self::assertSame($column, $exception->column);

            throw $exception;
        }
    }

    public static function unsupported_features(): array
    {
        return [
            'unterminated_single_quote_at_eof' => ["a: 'x", 'Multi-line quoted scalars are not supported', 1, 4],
            'single_quote_broken_by_line_break' => ["a: 'x\ny'", 'Multi-line quoted scalars are not supported', 1, 4],
            'unterminated_double_quote_at_line_start' => ['"x', 'Multi-line quoted scalars are not supported', 1, 1],
            'double_quote_broken_by_line_break' => ['"x\ny', 'Multi-line quoted scalars are not supported', 1, 1],
            'escaped_backslash_before_eof_in_double_quote' => ['"x\\', 'Multi-line quoted scalars are not supported', 1, 1],
            'escaped_backslash_before_line_break_in_double_quote' => ["\"x\\\n", 'Multi-line quoted scalars are not supported', 1, 1],
        ];
    }

    #[Test]
    #[DataProvider('scalar_styles')]
    public function scalar_style_is_attached_to_token(string $input, string $styleName): void
    {
        $scanner = new Scanner();

        $tokens = $scanner->scan($input);
        $scalar = $tokens[count($tokens) - 1];

        self::assertSame(TokenType::Scalar, $scalar->type);
        self::assertSame($styleName, $scalar->style->name);
    }

    public static function scalar_styles(): array
    {
        return [
            'plain' => ['plain text', 'Plain'],
            'single_quoted' => ["'quoted'", 'SingleQuoted'],
            'double_quoted' => ['"quoted"', 'DoubleQuoted'],
            'literal' => ["a: |\n  x", 'Literal'],
            'folded' => ["a: >\n  x", 'Folded'],
        ];
    }

    #[Test]
    #[DataProvider('block_scalar_headers')]
    public function block_scalar_header_is_carried_by_token(
        string $input,
        string $chompingName,
        ?int $explicitIndent,
    ): void {
        $scanner = new Scanner();

        $tokens = $scanner->scan($input);
        $scalar = $tokens[count($tokens) - 1];

        self::assertSame($chompingName, $scalar->blockHeader->chomping->name);
        self::assertSame($explicitIndent, $scalar->blockHeader->explicitIndent);
    }

    public static function block_scalar_headers(): array
    {
        return [
            'clip_by_default' => ["a: |\n  x", 'Clip', null],
            'strip' => ["a: |-\n  x", 'Strip', null],
            'keep' => ["a: |+\n  x", 'Keep', null],
            'explicit_indent_only' => ["a: |2\n  x", 'Clip', 2],
            'explicit_indent_with_strip' => ["a: |2-\n  x", 'Strip', 2],
            'strip_with_explicit_indent' => ["a: |-2\n  x", 'Strip', 2],
            'keep_with_explicit_indent' => ["a: |+3\n   x", 'Keep', 3],
            'folded_with_explicit_indent' => ["a: >2\n  x", 'Clip', 2],
        ];
    }

    #[Test]
    public function token_is_immutable_value_object_with_position(): void
    {
        $scanner = new Scanner();

        $tokens = $scanner->scan('a: b');
        $scalar = $tokens[0];

        self::assertInstanceOf(Token::class, $scalar);
        self::assertSame('a', $scalar->value);
        self::assertNull($scalar->blockHeader);
        self::assertSame(1, $scalar->position->line);
        self::assertSame(1, $scalar->position->column);
        self::assertSame(ScalarStyle::Plain, $scalar->style);
    }
}
