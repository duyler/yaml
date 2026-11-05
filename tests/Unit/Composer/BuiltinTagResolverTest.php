<?php

declare(strict_types=1);

namespace Duyler\Yaml\Test\Unit\Composer;

use Duyler\Yaml\Exception\InvalidTagValueException;
use Duyler\Yaml\Exception\UnsupportedTagException;
use Duyler\Yaml\Internal\Composer\BuiltinTagResolver;
use Duyler\Yaml\Internal\Scanner\Position;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use const INF;
use const NAN;

class BuiltinTagResolverTest extends TestCase
{
    private BuiltinTagResolver $resolver;

    private Position $position;

    protected function setUp(): void
    {
        $this->resolver = new BuiltinTagResolver();
        $this->position = new Position(line: 3, column: 7);
    }

    #[Test]
    #[DataProvider('builtin_scalar_tags')]
    public function resolves_scalar_tag_to_expected_value(string $tag, string|bool|int|float|null $value, mixed $expected): void
    {
        self::assertSame($expected, $this->resolver->resolve($tag, $value, $this->position));
    }

    public static function builtin_scalar_tags(): array
    {
        return [
            'null_from_empty_string' => ['tag:yaml.org,2002:null', '', null],
            'null_from_null' => ['tag:yaml.org,2002:null', null, null],
            'bool_true_from_bool' => ['tag:yaml.org,2002:bool', true, true],
            'bool_false_from_bool' => ['tag:yaml.org,2002:bool', false, false],
            'bool_true_from_string' => ['tag:yaml.org,2002:bool', 'true', true],
            'bool_false_from_string' => ['tag:yaml.org,2002:bool', 'False', false],
            'int_from_int' => ['tag:yaml.org,2002:int', 12, 12],
            'int_from_decimal_string' => ['tag:yaml.org,2002:int', '-42', -42],
            'int_from_hex_string' => ['tag:yaml.org,2002:int', '0x1F', 31],
            'int_from_octal_string' => ['tag:yaml.org,2002:int', '0o17', 15],
            'float_from_float' => ['tag:yaml.org,2002:float', 1.5, 1.5],
            'float_from_int' => ['tag:yaml.org,2002:float', 2, 2.0],
            'float_from_string' => ['tag:yaml.org,2002:float', '-2.5e3', -2500.0],
            'float_from_inf_string' => ['tag:yaml.org,2002:float', '.inf', INF],
            'float_from_negative_inf_string' => ['tag:yaml.org,2002:float', '-.INF', -INF],
            'str_from_int' => ['tag:yaml.org,2002:str', 12, '12'],
            'str_from_float' => ['tag:yaml.org,2002:str', 0.5, '0.5'],
            'str_from_bool' => ['tag:yaml.org,2002:str', true, 'true'],
            'str_from_null' => ['tag:yaml.org,2002:str', null, ''],
            'str_from_string' => ['tag:yaml.org,2002:str', 'text', 'text'],
            'binary_decodes_base64' => ['tag:yaml.org,2002:binary', 'aGVsbG8=', 'hello'],
            'binary_allows_spaces_and_newlines' => ['tag:yaml.org,2002:binary', "aGVs\r\n bG8=\t", 'hello'],
            'binary_empty_value' => ['tag:yaml.org,2002:binary', '', ''],
        ];
    }

    #[Test]
    #[DataProvider('invalid_tag_values')]
    public function invalid_value_under_builtin_tag_throws_with_coordinates(string $tag, string|bool|int|float|null $value, string $message): void
    {
        $this->expectException(InvalidTagValueException::class);

        try {
            $this->resolver->resolve($tag, $value, $this->position);
            self::fail(InvalidTagValueException::class . ' expected');
        } catch (InvalidTagValueException $exception) {
            self::assertSame('3:7: ' . $message, $exception->getMessage());

            throw $exception;
        }
    }

    public static function invalid_tag_values(): array
    {
        return [
            'null_rejects_text' => ['tag:yaml.org,2002:null', 'null', 'The "!!null" tag requires an empty or null value'],
            'null_rejects_number' => ['tag:yaml.org,2002:null', 12, 'The "!!null" tag requires an empty or null value'],
            'bool_rejects_text' => ['tag:yaml.org,2002:bool', 'x', 'Invalid value for the "!!bool" tag'],
            'bool_rejects_number' => ['tag:yaml.org,2002:bool', 1, 'Invalid value for the "!!bool" tag'],
            'bool_rejects_null' => ['tag:yaml.org,2002:bool', null, 'Invalid value for the "!!bool" tag'],
            'int_rejects_text' => ['tag:yaml.org,2002:int', 'abc', 'Invalid value for the "!!int" tag'],
            'int_rejects_float_string' => ['tag:yaml.org,2002:int', '12.5', 'Invalid value for the "!!int" tag'],
            'int_rejects_underscored' => ['tag:yaml.org,2002:int', '1_000', 'Invalid value for the "!!int" tag'],
            'int_rejects_float' => ['tag:yaml.org,2002:int', 1.5, 'Invalid value for the "!!int" tag'],
            'float_rejects_text' => ['tag:yaml.org,2002:float', 'abc', 'Invalid value for the "!!float" tag'],
            'float_rejects_null' => ['tag:yaml.org,2002:float', null, 'Invalid value for the "!!float" tag'],
            'binary_rejects_garbage' => ['tag:yaml.org,2002:binary', '***', 'Invalid value for the "!!binary" tag'],
            'binary_rejects_garbage_with_valid_length' => ['tag:yaml.org,2002:binary', 'aGVs$YQx', 'Invalid value for the "!!binary" tag'],
            'binary_rejects_truncated_input' => ['tag:yaml.org,2002:binary', 'aG', 'Invalid value for the "!!binary" tag'],
            'binary_rejects_number' => ['tag:yaml.org,2002:binary', 42, 'Invalid value for the "!!binary" tag'],
        ];
    }

    #[Test]
    #[DataProvider('unknown_global_tags')]
    public function unknown_global_tag_throws_unsupported_tag_exception(string $tag): void
    {
        $this->expectException(UnsupportedTagException::class);

        try {
            $this->resolver->resolve($tag, 'v', $this->position);
            self::fail(UnsupportedTagException::class . ' expected');
        } catch (UnsupportedTagException $exception) {
            self::assertSame('3:7: Unsupported tag "' . $tag . '"', $exception->getMessage());

            throw $exception;
        }
    }

    public static function unknown_global_tags(): array
    {
        return [
            'typo_shorthand_resolves_to_full_form' => ['tag:yaml.org,2002:itn'],
            'foreign_global_uri' => ['tag:example.com,2000:app/x'],
            'timestamp_is_out_of_scope' => ['tag:yaml.org,2002:timestamp'],
        ];
    }

    #[Test]
    public function nan_comes_back_as_nan(): void
    {
        self::assertTrue(NAN !== $this->resolver->resolve('tag:yaml.org,2002:float', '.nan', $this->position));
    }
}
