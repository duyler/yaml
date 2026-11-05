<?php

declare(strict_types=1);

namespace Duyler\Yaml\Internal\Composer;

use Duyler\Yaml\Exception\InvalidTagValueException;
use Duyler\Yaml\Exception\UnsupportedTagException;
use Duyler\Yaml\Internal\Scanner\Position;

use function base64_decode;
use function in_array;
use function is_string;
use function octdec;
use function preg_match;
use function sprintf;
use function str_replace;
use function strlen;
use function strval;
use function substr;
use function hexdec;

use function is_bool;
use function is_float;
use function is_int;

use const INF;
use const NAN;

final class BuiltinTagResolver
{
    private const string INT_DECIMAL_PATTERN = CoreSchemaPatterns::INT_DECIMAL_PATTERN;
    private const string INT_OCTAL_PATTERN = CoreSchemaPatterns::INT_OCTAL_PATTERN;
    private const string INT_HEX_PATTERN = CoreSchemaPatterns::INT_HEX_PATTERN;
    private const string FLOAT_PATTERN = CoreSchemaPatterns::FLOAT_PATTERN;
    private const string INF_PATTERN = CoreSchemaPatterns::INF_PATTERN;
    private const string NAN_PATTERN = CoreSchemaPatterns::NAN_PATTERN;

    /**
     * @param string|bool|int|float|null $value
     * @return string|bool|int|float|null
     */
    public function resolve(string $tag, string|bool|int|float|null $value, Position $position): string|bool|int|float|null
    {
        return match ($tag) {
            'tag:yaml.org,2002:null' => $this->asNull($value, $position),
            'tag:yaml.org,2002:bool' => $this->asBool($value, $position),
            'tag:yaml.org,2002:int' => $this->asInt($value, $position),
            'tag:yaml.org,2002:float' => $this->asFloat($value, $position),
            'tag:yaml.org,2002:str' => $this->asStr($value),
            'tag:yaml.org,2002:binary' => $this->asBinary($value, $position),
            default => throw new UnsupportedTagException(
                message: sprintf('Unsupported tag "%s"', $tag),
                lineNumber: $position->line,
                column: $position->column,
            ),
        };
    }

    private function asNull(string|bool|int|float|null $value, Position $position): null
    {
        if (null === $value || '' === $value) {
            return null;
        }

        throw new InvalidTagValueException(
            message: 'The "!!null" tag requires an empty or null value',
            lineNumber: $position->line,
            column: $position->column,
        );
    }

    private function asBool(string|bool|int|float|null $value, Position $position): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            if (in_array($value, ['true', 'True', 'TRUE'], true)) {
                return true;
            }
            if (in_array($value, ['false', 'False', 'FALSE'], true)) {
                return false;
            }
        }

        throw $this->invalidScalarValue('!!bool', $position);
    }

    private function asInt(string|bool|int|float|null $value, Position $position): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value)) {
            if (preg_match(self::INT_OCTAL_PATTERN, $value) === 1) {
                return (int) octdec(substr($value, 2));
            }
            if (preg_match(self::INT_HEX_PATTERN, $value) === 1) {
                return (int) hexdec(substr($value, 2));
            }
            if (preg_match(self::INT_DECIMAL_PATTERN, $value) === 1) {
                return (int) $value;
            }
        }

        throw $this->invalidScalarValue('!!int', $position);
    }

    private function asFloat(string|bool|int|float|null $value, Position $position): float
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw $this->invalidScalarValue('!!float', $position);
        }
        if (is_float($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (float) $value;
        }
        if (preg_match(self::INF_PATTERN, $value) === 1) {
            return '-' === $value[0] ? -INF : INF;
        }
        if (preg_match(self::NAN_PATTERN, $value) === 1) {
            return NAN;
        }
        if (preg_match(self::FLOAT_PATTERN, $value) === 1) {
            return (float) $value;
        }

        throw $this->invalidScalarValue('!!float', $position);
    }

    private function asStr(string|bool|int|float|null $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return strval($value);
    }

    private function asBinary(string|bool|int|float|null $value, Position $position): string
    {
        if (!is_string($value)) {
            throw $this->invalidScalarValue('!!binary', $position);
        }
        $normalized = str_replace([' ', "\t", "\r", "\n"], '', $value);
        if (0 !== strlen($normalized) % 4) {
            throw $this->invalidScalarValue('!!binary', $position);
        }
        $decoded = base64_decode($normalized, true);
        if (false === $decoded) {
            throw $this->invalidScalarValue('!!binary', $position);
        }

        return $decoded;
    }

    private function invalidScalarValue(string $tag, Position $position): InvalidTagValueException
    {
        return new InvalidTagValueException(
            message: sprintf('Invalid value for the "%s" tag', $tag),
            lineNumber: $position->line,
            column: $position->column,
        );
    }
}
