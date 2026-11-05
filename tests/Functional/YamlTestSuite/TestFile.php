<?php

declare(strict_types=1);

namespace Duyler\Yaml\Test\Functional\YamlTestSuite;

use function array_pop;
use function count;

use function explode;
use function implode;
use function preg_replace;
use function rtrim;
use function str_contains;
use function str_repeat;
use function str_replace;
use function strpos;
use function str_starts_with;
use function substr;

final class TestEntry
{
    public function __construct(
        public string $name,
        public string $yaml,
        public ?string $dump,
        public bool $fail,
    ) {}
}

final class TestFile
{
    /** @return list<TestEntry> */
    public static function parse(string $contents): array
    {
        $lines = explode("\n", self::stripDocumentMarker($contents));
        $entries = [];
        $index = 0;
        $length = count($lines);
        while ($index < $length) {
            $line = $lines[$index];
            if ('' === $line || !str_starts_with($line, '- ')) {
                $index++;
                continue;
            }
            $fields = [];
            self::consumeKeyLine('  ' . substr($line, 2), $fields, $lines, $index);
            while ($index < $length && !str_starts_with($lines[$index], '- ')) {
                $entryLine = $lines[$index];
                if ('' === $entryLine) {
                    $index++;
                    continue;
                }
                if (null === self::keyLineOf($entryLine)) {
                    $index++;
                    continue;
                }
                self::consumeKeyLine($entryLine, $fields, $lines, $index);
            }
            $entries[] = self::entryOf($fields);
        }

        return $entries;
    }

    public static function decode(string $value): string
    {
        $hadEndMarker = str_contains($value, '∎');
        $value = str_replace('∎', '', $value);
        if ($hadEndMarker) {
            $value = preg_replace('/\n$/', '', $value) ?? $value;
        }
        $value = str_replace('⇔', "\xEF\xBB\xBF", $value);
        $value = str_replace('←', "\r", $value);
        $value = str_replace(['———»', '——»', '—»', '»'], "\t", $value);

        return str_replace('␣', ' ', $value);
    }

    private static function stripDocumentMarker(string $contents): string
    {
        return str_starts_with($contents, "---\n") ? substr($contents, 4) : $contents;
    }

    /** @param array<string, string> $fields @param list<string> $lines */
    private static function consumeKeyLine(string $line, array &$fields, array $lines, int &$index): void
    {
        $inline = self::keyLineOf($line);
        if (null === $inline) {
            return;
        }
        [$key, $value] = $inline;
        $index++;
        if (self::isLiteralHeader($value)) {
            [$collected, $index] = self::collectLiteralBlock($lines, $index, self::chompingOf($value));
            $fields[$key] = $collected;

            return;
        }
        $fields[$key] = $value;
    }

    /** @return array{string, string}|null */
    private static function keyLineOf(string $line): ?array
    {
        if (!str_starts_with($line, '  ') || str_starts_with($line, '   ')) {
            return null;
        }
        $rest = substr($line, 2);
        $colon = strpos($rest, ':');
        if (false === $colon) {
            return null;
        }

        return [substr($rest, 0, $colon), substr($rest, $colon + 2)];
    }

    private static function isLiteralHeader(string $value): bool
    {
        return str_starts_with($value, '|');
    }

    private static function chompingOf(string $header): string
    {
        if (str_contains($header, '-')) {
            return 'strip';
        }
        if (str_contains($header, '+')) {
            return 'keep';
        }

        return 'clip';
    }

    /** @param list<string> $lines @return array{string, int} */
    private static function collectLiteralBlock(array $lines, int $index, string $chomping): array
    {
        $block = [];
        $pendingBlanks = 0;
        $length = count($lines);
        while ($index < $length) {
            $line = $lines[$index];
            if ('' === $line) {
                $pendingBlanks++;
                $index++;
                continue;
            }
            if (!str_starts_with($line, '    ')) {
                break;
            }
            for ($blank = 0; $blank < $pendingBlanks; $blank++) {
                $block[] = '';
            }
            $pendingBlanks = 0;
            $block[] = substr($line, 4);
            $index++;
        }
        while ([] !== $block && '' === $block[count($block) - 1]) {
            array_pop($block);
        }
        $text = implode("\n", $block);
        if ('' !== $text) {
            $text = match ($chomping) {
                'strip' => rtrim($text, "\n"),
                'keep' => $text . str_repeat("\n", 1 + $pendingBlanks),
                default => $text . "\n",
            };
        }

        return [$text, $index];
    }

    /** @param array<string, string> $fields */
    private static function entryOf(array $fields): TestEntry
    {
        return new TestEntry(
            name: $fields['name'] ?? 'unnamed',
            yaml: $fields['yaml'] ?? '',
            dump: $fields['dump'] ?? null,
            fail: 'true' === ($fields['fail'] ?? 'false'),
        );
    }
}
