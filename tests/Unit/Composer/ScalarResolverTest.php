<?php

declare(strict_types=1);

namespace Duyler\Yaml\Test\Unit\Composer;

use Duyler\Yaml\Exception\SyntaxException;
use Duyler\Yaml\Internal\Composer\ScalarIndicators;
use Duyler\Yaml\Internal\Composer\ScalarResolver;
use Duyler\Yaml\Internal\Scanner\Chomping;
use Duyler\Yaml\Internal\Scanner\Position;
use Duyler\Yaml\Internal\Scanner\ScalarStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use const INF;

class ScalarResolverTest extends TestCase
{
    private readonly ScalarResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ScalarResolver();
    }

    #[Test]
    #[DataProvider('plain_resolutions')]
    public function plain_scalar_resolves_according_to_core_schema_1_2(string $raw, string|bool|int|float|null $expected): void
    {
        $value = $this->resolver->resolve(ScalarStyle::Plain, $raw, new ScalarIndicators(), new Position(1, 1));

        self::assertSame($expected, $value);
    }

    public static function plain_resolutions(): array
    {
        return [
            'null_tilde' => ['~', null],
            'null_lower' => ['null', null],
            'null_title' => ['Null', null],
            'null_upper' => ['NULL', null],
            'null_empty' => ['', null],
            'bool_true_lower' => ['true', true],
            'bool_true_title' => ['True', true],
            'bool_true_upper' => ['TRUE', true],
            'bool_false_lower' => ['false', false],
            'bool_false_title' => ['False', false],
            'bool_false_upper' => ['FALSE', false],
            'int_zero' => ['0', 0],
            'int_positive' => ['42', 42],
            'int_negative' => ['-7', -7],
            'int_explicit_plus' => ['+7', 7],
            'deliberate_deviation_leading_zero_decimal_is_string' => ['007', '007'],
            'int_octal' => ['0o17', 15],
            'int_octal_zero' => ['0o0', 0],
            'int_hexadecimal' => ['0xFF', 255],
            'int_hexadecimal_lower_digits' => ['0xff', 255],
            'int_hexadecimal_zero' => ['0x0', 0],
            'float_fraction' => ['0.5', 0.5],
            'float_negative' => ['-0.5', -0.5],
            'float_leading_dot' => ['.5', 0.5],
            'float_explicit_plus_dot' => ['+.5', 0.5],
            'float_trailing_dot' => ['5.', 5.0],
            'float_exponent' => ['1e3', 1000.0],
            'float_exponent_upper' => ['1E3', 1000.0],
            'float_exponent_plus' => ['1e+3', 1000.0],
            'float_exponent_negative' => ['1e-3', 0.001],
            'float_negative_exponent_negative' => ['-1.25e-2', -0.0125],
            'float_zero' => ['0.0', 0.0],
            'float_whole' => ['1.0', 1.0],
            'float_positive_inf' => ['.inf', INF],
            'float_positive_inf_title' => ['.Inf', INF],
            'float_positive_inf_upper' => ['.INF', INF],
            'float_positive_inf_explicit_plus' => ['+.inf', INF],
            'float_positive_inf_explicit_plus_title' => ['+.Inf', INF],
            'float_positive_inf_explicit_plus_upper' => ['+.INF', INF],
            'float_negative_inf' => ['-.inf', -INF],
            'float_negative_inf_title' => ['-.Inf', -INF],
            'float_negative_inf_upper' => ['-.INF', -INF],
            'equals_sign_is_string' => ['=', '='],
            'string_words' => ['hello world', 'hello world'],
            'string_single_char' => ['x', 'x'],
            'string_negative_only' => ['-', '-'],
            'string_plus_only' => ['+', '+'],
            'string_dot_only' => ['.', '.'],
            'string_with_spaces' => [' padded ', ' padded '],
            'string_float_like_double_dot' => ['1.2.3', '1.2.3'],
            'string_float_like_suffix' => ['1.2x', '1.2x'],
            'string_exponent_without_base' => ['e5', 'e5'],
            'string_hex_without_digits' => ['0x', '0x'],
            'string_octal_without_digits' => ['0o', '0o'],
            'deliberate_deviation_from_1_1_yes_is_string' => ['yes', 'yes'],
            'deliberate_deviation_from_1_1_no_is_string' => ['no', 'no'],
            'deliberate_deviation_from_1_1_on_is_string' => ['on', 'on'],
            'deliberate_deviation_from_1_1_off_is_string' => ['off', 'off'],
            'deliberate_deviation_from_1_1_underscored_number_is_string' => ['1_000', '1_000'],
            'deliberate_deviation_from_1_1_leading_zero_is_string' => ['010', '010'],
            'deliberate_deviation_from_1_1_sexpages_is_string' => ['12:30:00', '12:30:00'],
            'deliberate_deviation_from_1_1_date_is_string' => ['2001-12-14', '2001-12-14'],
            'deliberate_deviation_from_1_1_sexagesimal_int_is_string' => ['190:20:30', '190:20:30'],
            'deliberate_deviation_signed_hex_is_string' => ['-0xFF', '-0xFF'],
            'deliberate_deviation_signed_octal_is_string' => ['-0o17', '-0o17'],
        ];
    }

    #[Test]
    public function plain_nan_resolves_to_nan(): void
    {
        foreach (['.nan', '.NaN', '.NAN'] as $raw) {
            $value = $this->resolver->resolve(ScalarStyle::Plain, $raw, new ScalarIndicators(), new Position(1, 1));
            self::assertTrue(is_nan($value), $raw . ' must resolve to NAN');
        }
    }

    #[Test]
    #[DataProvider('single_quoted_resolutions')]
    public function single_quoted_scalar_unfolds_doubled_quotes(string $raw, string $expected): void
    {
        $value = $this->resolver->resolve(ScalarStyle::SingleQuoted, $raw, new ScalarIndicators(), new Position(1, 1));

        self::assertSame($expected, $value);
    }

    public static function single_quoted_resolutions(): array
    {
        return [
            'empty' => ["''", ''],
            'plain' => ["'x'", 'x'],
            'with_escaped_quote' => ["'a ''b'''", "a 'b'"],
            'escaped_quote_only' => ["''''", "'"],
            'multiple_escaped_quotes' => ["'x''y''z'", "x'y'z"],
            'backslash_is_literal' => ["'a\\b'", 'a\\b'],
        ];
    }

    #[Test]
    #[DataProvider('double_quoted_resolutions')]
    public function double_quoted_scalar_decodes_escapes_1_2_2(string $raw, string $expected): void
    {
        $value = $this->resolver->resolve(ScalarStyle::DoubleQuoted, $raw, new ScalarIndicators(), new Position(1, 1));

        self::assertSame($expected, $value);
    }

    public static function double_quoted_resolutions(): array
    {
        return [
            'empty' => ['""', ''],
            'plain' => ['"x"', 'x'],
            'escaped_quote' => ['"a \\"b\\""', 'a "b"'],
            'escaped_backslash' => ['"a\\\\b"', 'a\\b'],
            'escape_null' => ['"\\0"', "\x00"],
            'escape_bell' => ['"\\a"', "\x07"],
            'escape_backspace' => ['"\\b"', "\x08"],
            'escape_tab' => ['"\\t"', "\t"],
            'escape_newline' => ['"\\n"', "\n"],
            'escape_vertical_tab' => ['"\\v"', "\x0B"],
            'escape_form_feed' => ['"\\f"', "\x0C"],
            'escape_carriage_return' => ['"\\r"', "\r"],
            'escape_escape' => ['"\\e"', "\x1B"],
            'escape_next_line' => ['"\\N"', "\xC2\x85"],
            'escape_non_breaking_space' => ['"\\_"', "\xC2\xA0"],
            'escape_line_separator' => ['"\\L"', "\xE2\x80\xA8"],
            'escape_paragraph_separator' => ['"\\P"', "\xE2\x80\xA9"],
            'escape_hex_byte' => ['"\\x41"', 'A'],
            'escape_hex_byte_lowercase_hex' => ['"\\x4a"', 'J'],
            'escape_unicode_bmp' => ['"\\u0041"', 'A'],
            'escape_unicode_cyrillic' => ['"\\u043A"', 'к'],
            'escape_unicode_supplementary_plane' => ['"\\U0001F600"', "\xF0\x9F\x98\x80"],
            'escape_unicode_three_byte' => ['"\\u20AC"', "\xE2\x82\xAC"],
            'combined_escapes' => ['"a\\nb\\tc"', "a\nb\tc"],
        ];
    }

    #[Test]
    public function invalid_hex_escape_throws_with_coordinates(): void
    {
        $this->expectException(SyntaxException::class);

        try {
            $this->resolver->resolve(
                ScalarStyle::DoubleQuoted,
                '"\\xZZ"',
                new ScalarIndicators(),
                new Position(3, 7),
            );
            self::fail('SyntaxException expected');
        } catch (SyntaxException $exception) {
            self::assertSame('3:7: Invalid escape sequence in double-quoted scalar', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    public function surrogate_code_point_escape_throws_with_coordinates(): void
    {
        $this->expectException(SyntaxException::class);

        try {
            $this->resolver->resolve(
                ScalarStyle::DoubleQuoted,
                '"\\uD800"',
                new ScalarIndicators(),
                new Position(2, 4),
            );
            self::fail('SyntaxException expected');
        } catch (SyntaxException $exception) {
            self::assertSame('2:4: Escape sequence resolves to an invalid Unicode code point', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    public function code_point_above_unicode_ceiling_throws_with_coordinates(): void
    {
        $this->expectException(SyntaxException::class);

        try {
            $this->resolver->resolve(
                ScalarStyle::DoubleQuoted,
                '"\\U00110000"',
                new ScalarIndicators(),
                new Position(2, 4),
            );
            self::fail('SyntaxException expected');
        } catch (SyntaxException $exception) {
            self::assertSame('2:4: Escape sequence resolves to an invalid Unicode code point', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    public function maximum_code_point_throws_with_coordinates(): void
    {
        $this->expectException(SyntaxException::class);

        try {
            $this->resolver->resolve(
                ScalarStyle::DoubleQuoted,
                '"\\U7FFFFFFF"',
                new ScalarIndicators(),
                new Position(2, 4),
            );
            self::fail('SyntaxException expected');
        } catch (SyntaxException $exception) {
            self::assertSame('2:4: Escape sequence resolves to an invalid Unicode code point', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    public function unicode_ceiling_code_point_is_accepted(): void
    {
        self::assertSame(
            "\xF4\x8F\xBF\xBF",
            $this->resolver->resolve(ScalarStyle::DoubleQuoted, '"\\U0010FFFF"', new ScalarIndicators(), new Position(1, 1)),
        );
    }

    #[Test]
    public function unknown_escape_letter_throws_with_coordinates(): void
    {
        $this->expectException(SyntaxException::class);

        try {
            $this->resolver->resolve(
                ScalarStyle::DoubleQuoted,
                '"\\q"',
                new ScalarIndicators(),
                new Position(1, 2),
            );
            self::fail('SyntaxException expected');
        } catch (SyntaxException $exception) {
            self::assertSame('1:2: Invalid escape sequence in double-quoted scalar', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    #[DataProvider('literal_scalars')]
    public function literal_scalar_applies_chomping(string $raw, Chomping $chomping, string $expected): void
    {
        $value = $this->resolver->resolve(
            ScalarStyle::Literal,
            $raw,
            new ScalarIndicators(chomping: $chomping),
            new Position(1, 1),
        );

        self::assertSame($expected, $value);
    }

    public static function literal_scalars(): array
    {
        return [
            'empty_block_clip' => ['', Chomping::Clip, ''],
            'empty_block_strip' => ['', Chomping::Strip, ''],
            'empty_block_keep' => ['', Chomping::Keep, ''],
            'single_line_clip' => ['x', Chomping::Clip, "x\n"],
            'single_line_strip' => ['x', Chomping::Strip, 'x'],
            'single_line_keep' => ['x', Chomping::Keep, "x\n"],
            'two_lines_clip' => ["x\ny", Chomping::Clip, "x\ny\n"],
            'two_lines_strip' => ["x\ny", Chomping::Strip, "x\ny"],
            'two_lines_keep' => ["x\ny", Chomping::Keep, "x\ny\n"],
            'trailing_blank_lines_clip' => ["x\n\n", Chomping::Clip, "x\n"],
            'trailing_blank_lines_strip' => ["x\n\n", Chomping::Strip, 'x'],
            'trailing_blank_lines_keep' => ["x\n\n", Chomping::Keep, "x\n\n\n"],
            'inner_blank_lines_clip' => ["x\n\ny", Chomping::Clip, "x\n\ny\n"],
            'inner_blank_lines_keep' => ["x\n\ny", Chomping::Keep, "x\n\ny\n"],
        ];
    }

    #[Test]
    #[DataProvider('folded_scalars')]
    public function folded_scalar_folds_breaks_and_applies_chomping(string $raw, Chomping $chomping, string $expected): void
    {
        $value = $this->resolver->resolve(
            ScalarStyle::Folded,
            $raw,
            new ScalarIndicators(chomping: $chomping),
            new Position(1, 1),
        );

        self::assertSame($expected, $value);
    }

    public static function folded_scalars(): array
    {
        return [
            'empty_block_clip' => ['', Chomping::Clip, ''],
            'empty_block_strip' => ['', Chomping::Strip, ''],
            'empty_block_keep' => ['', Chomping::Keep, ''],
            'single_line_clip' => ['x', Chomping::Clip, "x\n"],
            'single_line_strip' => ['x', Chomping::Strip, 'x'],
            'two_lines_fold_to_space_clip' => ["a\nb", Chomping::Clip, "a b\n"],
            'two_lines_fold_to_space_strip' => ["a\nb", Chomping::Strip, 'a b'],
            'two_lines_fold_to_space_keep' => ["a\nb", Chomping::Keep, "a b\n"],
            'three_lines_clip' => ["a\nb\nc", Chomping::Clip, "a b c\n"],
            'blank_line_between_lines_clip' => ["a\n\nb", Chomping::Clip, "a\nb\n"],
            'two_blank_lines_between_lines_clip' => ["a\n\n\nb", Chomping::Clip, "a\n\nb\n"],
            'leading_blank_line_clip' => ["\na", Chomping::Clip, "\na\n"],
            'trailing_blank_line_clip' => ["a\n", Chomping::Clip, "a\n"],
            'trailing_blank_line_keep' => ["a\n", Chomping::Keep, "a\n\n"],
            'spec_example_more_indented_bullet_clip' => [
                "folded\nline\n\nnext\nline\n * bullet\n\nlast",
                Chomping::Clip,
                "folded line\nnext line\n * bullet\n\nlast\n",
            ],
            'spec_example_more_indented_bullet_strip' => [
                "folded\nline\n\nnext\nline\n * bullet\n\nlast",
                Chomping::Strip,
                'folded line' . "\n" . 'next line' . "\n" . ' * bullet' . "\n\n" . 'last',
            ],
        ];
    }
}
