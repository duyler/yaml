<?php

declare(strict_types=1);

namespace Duyler\Yaml\Internal\Composer;

use Duyler\Yaml\Exception\SyntaxException;
use Duyler\Yaml\Internal\Scanner\Chomping;
use Duyler\Yaml\Internal\Scanner\Position;
use Duyler\Yaml\Internal\Scanner\ScalarStyle;

use function preg_match;
use function preg_replace_callback;
use function rtrim;
use function str_replace;
use function str_repeat;
use function substr;
use function chr;
use function in_array;

use const INF;
use const NAN;

final class ScalarResolver
{
    private const string INT_DECIMAL_PATTERN = CoreSchemaPatterns::INT_DECIMAL_PATTERN;
    private const string INT_OCTAL_PATTERN = CoreSchemaPatterns::INT_OCTAL_PATTERN;
    private const string INT_HEX_PATTERN = CoreSchemaPatterns::INT_HEX_PATTERN;
    private const string FLOAT_PATTERN = CoreSchemaPatterns::FLOAT_PATTERN;
    private const string INF_PATTERN = CoreSchemaPatterns::INF_PATTERN;
    private const string NAN_PATTERN = CoreSchemaPatterns::NAN_PATTERN;
    private const string ESCAPE_PATTERN = '/\\\\(?:([0abtnvfre"\\\\N_LP])|x([0-9A-Fa-f]{2})|u([0-9A-Fa-f]{4})|U([0-9A-Fa-f]{8}))/';
    private const string INVALID_ESCAPE_PATTERN = '/\\\\(?:[^0abtnvfre"\\\\N_LPxuU]|x(?![0-9A-Fa-f]{2})|u(?![0-9A-Fa-f]{4})|U(?![0-9A-Fa-f]{8}))/';

    /** @return string|bool|int|float|null */
    public function resolve(ScalarStyle $style, string $raw, ScalarIndicators $indicators, Position $position): string|bool|int|float|null
    {
        return match ($style) {
            ScalarStyle::Plain => $this->resolvePlain($raw),
            ScalarStyle::SingleQuoted => str_replace("''", "'", substr($raw, 1, -1)),
            ScalarStyle::DoubleQuoted => $this->decodeDoubleQuotedEscapes(substr($raw, 1, -1), $position),
            ScalarStyle::Literal => '' === $raw ? '' : $this->applyChomping($raw . "\n", $indicators->chomping),
            ScalarStyle::Folded => '' === $raw ? '' : $this->applyChomping($this->fold($raw) . "\n", $indicators->chomping),
        };
    }

    /** @return string|bool|int|float|null */
    private function resolvePlain(string $raw): string|bool|int|float|null
    {
        return match (true) {
            $this->isNull($raw) => null,
            $this->isBool($raw) => $this->asBool($raw),
            $this->isInt($raw) => $this->asInt($raw),
            $this->isFloat($raw) => $this->asFloat($raw),
            default => $raw,
        };
    }

    private function isNull(string $raw): bool
    {
        return '' === $raw || '~' === $raw || in_array($raw, ['null', 'Null', 'NULL'], true);
    }

    private function isBool(string $raw): bool
    {
        return in_array($raw, ['true', 'True', 'TRUE', 'false', 'False', 'FALSE'], true);
    }

    private function asBool(string $raw): bool
    {
        return !in_array($raw, ['false', 'False', 'FALSE'], true);
    }

    private function isInt(string $raw): bool
    {
        if (preg_match(self::INT_DECIMAL_PATTERN, $raw) === 1) {
            return true;
        }
        if (preg_match(self::INT_OCTAL_PATTERN, $raw) === 1) {
            return true;
        }

        return preg_match(self::INT_HEX_PATTERN, $raw) === 1;
    }

    private function asInt(string $raw): int
    {
        if (preg_match(self::INT_OCTAL_PATTERN, $raw) === 1) {
            return (int) octdec(substr($raw, 2));
        }
        if (preg_match(self::INT_HEX_PATTERN, $raw) === 1) {
            return (int) hexdec(substr($raw, 2));
        }

        return (int) $raw;
    }

    private function isFloat(string $raw): bool
    {
        if (preg_match(self::INF_PATTERN, $raw) === 1) {
            return true;
        }
        if (preg_match(self::NAN_PATTERN, $raw) === 1) {
            return true;
        }

        return preg_match(self::FLOAT_PATTERN, $raw) === 1;
    }

    private function asFloat(string $raw): float
    {
        if (preg_match(self::INF_PATTERN, $raw) === 1) {
            return '-' === $raw[0] ? -INF : INF;
        }
        if (preg_match(self::NAN_PATTERN, $raw) === 1) {
            return NAN;
        }

        return (float) $raw;
    }

    private function decodeDoubleQuotedEscapes(string $raw, Position $position): string
    {
        if (preg_match(self::INVALID_ESCAPE_PATTERN, $raw) === 1) {
            throw new SyntaxException(
                message: 'Invalid escape sequence in double-quoted scalar',
                lineNumber: $position->line,
                column: $position->column,
            );
        }

        $decoded = preg_replace_callback(
            self::ESCAPE_PATTERN,
            function (array $matches) use ($position): string {
                if ('' !== ($matches[1] ?? '')) {
                    return self::decodeEscape($matches);
                }
                $hexDigits = '' !== ($matches[2] ?? '')
                    ? $matches[2]
                    : ('' !== ($matches[3] ?? '') ? $matches[3] : ($matches[4] ?? ''));

                return $this->codePointToUtf8((int) hexdec($hexDigits), $position);
            },
            $raw,
        );

        if (null === $decoded) {
            throw new SyntaxException(
                message: 'PCRE failure while decoding escape sequences',
                lineNumber: $position->line,
                column: $position->column,
            );
        }

        return $decoded;
    }

    /** @param array<array-key, string|null> $matches */
    private static function decodeEscape(array $matches): string
    {
        return match ($matches[1] ?? '') {
            '0' => "\x00",
            'a' => "\x07",
            'b' => "\x08",
            't' => "\t",
            'n' => "\n",
            'v' => "\x0B",
            'f' => "\x0C",
            'r' => "\r",
            'e' => "\x1B",
            '"' => '"',
            '\\' => '\\',
            'N' => "\xC2\x85",
            '_' => "\xC2\xA0",
            'L' => "\xE2\x80\xA8",
            'P' => "\xE2\x80\xA9",
            default => $matches[0] ?? '',
        };
    }

    private function codePointToUtf8(int $codePoint, Position $position): string
    {
        if ($codePoint > 0x10FFFF || ($codePoint >= 0xD800 && $codePoint <= 0xDFFF)) {
            throw new SyntaxException(
                message: 'Escape sequence resolves to an invalid Unicode code point',
                lineNumber: $position->line,
                column: $position->column,
            );
        }
        if ($codePoint < 0x80) {
            return chr($codePoint);
        }
        if ($codePoint < 0x800) {
            return chr(0xC0 | ($codePoint >> 6)) . chr(0x80 | ($codePoint & 0x3F));
        }
        if ($codePoint < 0x10000) {
            return chr(0xE0 | ($codePoint >> 12))
                . chr(0x80 | (($codePoint >> 6) & 0x3F))
                . chr(0x80 | ($codePoint & 0x3F));
        }

        return chr(0xF0 | ($codePoint >> 18))
            . chr(0x80 | (($codePoint >> 12) & 0x3F))
            . chr(0x80 | (($codePoint >> 6) & 0x3F))
            . chr(0x80 | ($codePoint & 0x3F));
    }

    private function applyChomping(string $content, Chomping $chomping): string
    {
        return match ($chomping) {
            Chomping::Strip => rtrim($content, "\n"),
            Chomping::Clip => rtrim($content, "\n") . "\n",
            Chomping::Keep => $content,
        };
    }

    private function fold(string $raw): string
    {
        $lines = explode("\n", $raw);
        $result = '';
        $breaks = 0;
        $previousMoreIndented = false;
        foreach ($lines as $line) {
            $moreIndented = '' !== $line && ' ' === $line[0];
            if ('' === $line) {
                $breaks++;
                continue;
            }
            if ('' === $result) {
                $result = str_repeat("\n", $breaks) . $line;
            } else {
                if ($moreIndented || $previousMoreIndented) {
                    $result .= str_repeat("\n", $breaks + 1);
                } elseif (0 === $breaks) {
                    $result .= ' ';
                } else {
                    $result .= str_repeat("\n", $breaks);
                }
                $result .= $line;
            }
            $breaks = 0;
            $previousMoreIndented = $moreIndented;
        }
        $result .= str_repeat("\n", $breaks);

        return $result;
    }
}
