<?php

declare(strict_types=1);

namespace Duyler\Yaml\Test\Unit\Scanner;

use Duyler\Yaml\Internal\Scanner\Scanner;
use Duyler\Yaml\Yaml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ScannerCrlfContinuationTest extends TestCase
{
    #[Test]
    #[DataProvider('crlf_continuation_inputs')]
    public function crlf_continuation_lines_fold_like_lf(string $input, array $expected): void
    {
        $scanner = new Scanner();

        $actual = [];
        foreach ($scanner->scan($input) as $token) {
            $actual[] = [$token->type->name, $token->value, $token->position->line, $token->position->column];
        }

        self::assertSame($expected, $actual);
    }

    public static function crlf_continuation_inputs(): array
    {
        return [
            'crlf_continuation_lines_fold_into_space' => [
                "a: x\r\n  y\r\n  z",
                [['Scalar', 'a', 1, 1], ['MappingValue', ':', 1, 2], ['Scalar', 'x y z', 1, 4]],
            ],
            'crlf_blank_line_folds_into_line_break' => [
                "a: x\r\n\r\n  y",
                [['Scalar', 'a', 1, 1], ['MappingValue', ':', 1, 2], ['Scalar', "x\ny", 1, 4]],
            ],
            'lf_start_with_crlf_blank_line_folds_into_line_break' => [
                "a: x\n\r\n  y",
                [['Scalar', 'a', 1, 1], ['MappingValue', ':', 1, 2], ['Scalar', "x\ny", 1, 4]],
            ],
            'crlf_continuation_in_sequence_entry' => [
                "- x\r\n  y",
                [['SequenceEntry', '-', 1, 1], ['Scalar', 'x y', 1, 3]],
            ],
        ];
    }

    #[Test]
    #[DataProvider('crlf_plain_scalar_values')]
    public function crlf_multiline_plain_value_has_no_carriage_return(string $input, string $expectedValue): void
    {
        self::assertSame(['a' => $expectedValue], Yaml::parse($input));
    }

    public static function crlf_plain_scalar_values(): array
    {
        return [
            'two_crlf_continuations_resolve_to_spaces' => ["a: x\r\n  y\r\n  z", 'x y z'],
            'crlf_blank_line_resolves_to_line_break' => ["a: x\r\n\r\n  y", "x\ny"],
        ];
    }
}
