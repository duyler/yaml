<?php

declare(strict_types=1);

namespace Duyler\Yaml\Test\Functional\YamlTestSuite;

use Duyler\Yaml\Exception\ParseException;
use Duyler\Yaml\TaggedValue;
use Duyler\Yaml\Yaml;
use PHPUnit\Framework\Attributes\DataProvider;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function basename;

use function explode;
use function file_get_contents;
use function glob;
use function is_array;
use function str_contains;
use function trim;

final class YamlTestSuiteTest extends TestCase
{
    private const string FIXTURES_DIR = __DIR__ . '/../../../fixtures/yaml-test-suite';

    /** @return array<string, array{string, string, string}> */
    public static function positiveFixturesProvider(): array
    {
        return self::casesOf(
            static fn(TestEntry $entry): bool => !$entry->fail && null !== $entry->dump,
            static fn(string $filename, TestEntry $entry, string $label): array => [
                $filename,
                TestFile::decode((string) $entry->dump),
                TestFile::decode($entry->yaml),
            ],
        );
    }

    /** @return array<string, array{string, string}> */
    public static function errorFixturesProvider(): array
    {
        return self::casesOf(
            static fn(TestEntry $entry): bool => $entry->fail,
            static fn(string $filename, TestEntry $entry, string $label): array => [
                $filename,
                TestFile::decode($entry->yaml),
            ],
        );
    }

    #[Test]
    #[DataProvider('positiveFixturesProvider')]
    public function positive_fixture_matches_reference_dump(string $filename, string $expected, string $input): void
    {
        self::assertSame(
            self::normalize(Yaml::parseAll($expected)),
            self::normalize(Yaml::parseAll($input)),
            'Oracle mismatch in ' . $filename,
        );
    }

    #[Test]
    #[DataProvider('errorFixturesProvider')]
    public function error_fixture_throws_parse_exception_with_coordinates(string $filename, string $input): void
    {
        try {
            Yaml::parseAll($input);
            self::fail('ParseException expected in ' . $filename);
        } catch (ParseException $exception) {
            self::assertTrue(
                str_contains($exception->getMessage(), ':'),
                'Error test must carry coordinates in ' . $filename,
            );
        }
    }

    #[Test]
    public function whitelist_and_fixtures_directory_are_in_sync(): void
    {
        $whitelisted = [];
        $lines = explode("\n", (string) file_get_contents(__DIR__ . '/allowed-tests.txt'));
        foreach ($lines as $filename) {
            $filename = trim($filename);
            if ('' !== $filename) {
                $whitelisted[$filename] = true;
            }
        }
        $vendored = [];
        foreach (glob(self::FIXTURES_DIR . '/*.yaml') ?: [] as $path) {
            $basename = basename($path, '.yaml');
            $vendored[$basename] = true;
        }

        self::assertSame($whitelisted, $vendored);
    }

    /** @return array<string, array{string, string}>|array<string, array{string, string, string}> */
    private static function casesOf(callable $include, callable $arguments): array
    {
        $cases = [];
        $lines = explode("\n", (string) file_get_contents(__DIR__ . '/allowed-tests.txt'));
        foreach ($lines as $filename) {
            $filename = trim($filename);
            if ('' === $filename) {
                continue;
            }
            foreach (TestFile::parse((string) file_get_contents(self::FIXTURES_DIR . '/' . $filename . '.yaml')) as $entry) {
                if (!$include($entry)) {
                    continue;
                }
                $label = 'unnamed' === $entry->name
                    ? $filename . ' unnamed subtest'
                    : $filename . ' ' . $entry->name;
                $cases[$label] = $arguments($filename, $entry, $label);
            }
        }

        return $cases;
    }

    /** @return mixed */
    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof TaggedValue) {
            return ['tag' => $value->tag, 'value' => self::normalize($value->value)];
        }
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = self::normalize($item);
            }

            return $normalized;
        }

        return $value;
    }
}
