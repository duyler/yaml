<?php

declare(strict_types=1);

namespace Duyler\Yaml\Test\Functional;

use Duyler\Yaml\Exception\DirectiveException;
use Duyler\Yaml\Exception\VersionException;
use Duyler\Yaml\Exception\UnsupportedTagException;
use Duyler\Yaml\Yaml;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class YamlDirectivesTest extends TestCase
{
    #[Test]
    public function yaml_directive_with_supported_version_passes(): void
    {
        self::assertSame(['a' => 1], Yaml::parse("%YAML 1.2\n---\na: 1"));
    }

    #[Test]
    public function yaml_directive_with_unsupported_version_throws(): void
    {
        $this->expectException(VersionException::class);

        try {
            Yaml::parseAll("%YAML 1.1\n---\na: 1");
            self::fail(VersionException::class . ' expected');
        } catch (VersionException $exception) {
            self::assertSame('1:1: Unsupported YAML version "1.1"', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    public function yaml_directive_is_per_document(): void
    {
        $input = "%YAML 1.2\n---\na: 1\n---\nb: 2";

        self::assertSame([['a' => 1], ['b' => 2]], Yaml::parseAll($input));
    }

    #[Test]
    public function duplicate_yaml_directive_throws(): void
    {
        $this->expectException(DirectiveException::class);

        try {
            Yaml::parse("%YAML 1.2\n%YAML 1.2\n---\na: 1");
            self::fail(DirectiveException::class . ' expected');
        } catch (DirectiveException $exception) {
            self::assertSame('2:1: Duplicate %YAML directive', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    public function tag_directive_expands_unknown_global_uri_throws(): void
    {
        $input = "%TAG !e! tag:example.com,2000:\n---\nroot: !e!custom v";

        $this->expectException(UnsupportedTagException::class);

        try {
            Yaml::parse($input);
            self::fail(UnsupportedTagException::class . ' expected');
        } catch (UnsupportedTagException $exception) {
            self::assertSame('3:17: Unsupported tag "tag:example.com,2000:custom"', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    public function tag_directive_expands_known_builtin_shorthand(): void
    {
        $input = "%TAG !! tag:yaml.org,2002:\n---\nroot: !!str 12";

        self::assertSame(['root' => '12'], Yaml::parse($input));
    }

    #[Test]
    public function unknown_directive_throws(): void
    {
        $this->expectException(DirectiveException::class);

        try {
            Yaml::parse("%FOO bar\n---\na: 1");
            self::fail(DirectiveException::class . ' expected');
        } catch (DirectiveException $exception) {
            self::assertSame('1:1: Unknown directive "%FOO"', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    public function directive_after_content_throws(): void
    {
        $this->expectException(DirectiveException::class);

        try {
            Yaml::parseAll("a: 1\n---\nb: 2\n%YAML 1.2\n---\nc: 3");
            self::fail(DirectiveException::class . ' expected');
        } catch (DirectiveException $exception) {
            self::assertSame(4, $exception->lineNumber);

            throw $exception;
        }
    }
}
