<?php

declare(strict_types=1);

namespace Duyler\Yaml\Test\Unit\Internal;

use Duyler\Yaml\Exception\InvalidEncodingException;
use Duyler\Yaml\Exception\ParseException;
use Duyler\Yaml\Exception\YamlException;
use Duyler\Yaml\Internal\InputValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

class InputValidatorTest extends TestCase
{
    #[Test]
    #[DataProvider('valid_inputs')]
    public function valid_input_is_returned_as_is(string $input): void
    {
        $validator = new InputValidator();

        self::assertSame($input, $validator->validate($input));
    }

    public static function valid_inputs(): array
    {
        return [
            'empty_input' => [''],
            'plain_scalar' => ['key: value'],
            'multiline_document' => ["first: 1\nsecond: two\nthird: [a, b]"],
            'unicode_content' => ['ключ: значение'],
            'tab_and_crlf_content' => ["key:\tvalue\r\nnext: line"],
        ];
    }

    #[Test]
    #[DataProvider('inputs_with_leading_bom')]
    public function leading_bom_is_stripped(string $input, string $expected): void
    {
        $validator = new InputValidator();

        self::assertSame($expected, $validator->validate($input));
    }

    public static function inputs_with_leading_bom(): array
    {
        return [
            'bom_only' => ["\xEF\xBB\xBF", ''],
            'bom_before_scalar' => ["\xEF\xBB\xBFkey: value", 'key: value'],
            'bom_before_multiline' => ["\xEF\xBB\xBFa\nb", "a\nb"],
        ];
    }

    #[Test]
    public function bom_bytes_in_middle_of_valid_utf8_are_preserved(): void
    {
        $validator = new InputValidator();
        $input = "key: value\xEF\xBB\xBFnext";

        self::assertSame($input, $validator->validate($input));
    }

    #[Test]
    #[DataProvider('invalid_inputs')]
    public function invalid_input_throws_with_first_invalid_byte_coordinates(
        string $input,
        int $line,
        int $column,
    ): void {
        $validator = new InputValidator();

        $this->expectException(InvalidEncodingException::class);

        try {
            $validator->validate($input);
            self::fail('Invalid UTF-8 input must not pass validation');
        } catch (InvalidEncodingException $exception) {
            self::assertSame($line, $exception->lineNumber);
            self::assertSame($column, $exception->column);
            self::assertGreaterThanOrEqual(1, $exception->lineNumber);
            self::assertGreaterThanOrEqual(1, $exception->column);
            self::assertNull($exception->fileName);

            throw $exception;
        }
    }

    public static function invalid_inputs(): array
    {
        return [
            'single_invalid_byte_ff' => ["a\xFFb", 1, 2],
            'invalid_byte_in_first_line' => ["\xFFstart", 1, 1],
            'invalid_byte_in_last_line' => ["ok\nok\nok\xFF", 3, 3],
            'invalid_byte_in_middle_of_multiline' => ["ok\nbad\xFF\nok\nok", 2, 4],
            'broken_bom_sequence_in_middle' => ["line1\nli\xEF\xBBne", 2, 3],
            'truncated_multibyte_at_line_end' => ["doc:\n  - \xC3\xA9\n  - \xE2\x82", 3, 5],
            'invalid_byte_on_line_scan_boundary' => [str_repeat("ok\n", 36) . "\xFF rest", 37, 1],
            'invalid_byte_on_column_scan_boundary' => [str_repeat('a', 100) . "\xE2\x82 tail", 1, 101],
        ];
    }

    #[Test]
    public function invalid_encoding_exception_is_part_of_parse_hierarchy(): void
    {
        $validator = new InputValidator();

        try {
            $validator->validate("a\xFFb");
            self::fail('Invalid UTF-8 input must not pass validation');
        } catch (InvalidEncodingException $exception) {
            self::assertInstanceOf(ParseException::class, $exception);
            self::assertInstanceOf(YamlException::class, $exception);
            self::assertSame('1:2: Invalid UTF-8 input', $exception->getMessage());
        }
    }
}
