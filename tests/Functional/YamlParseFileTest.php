<?php

declare(strict_types=1);

namespace Duyler\Yaml\Test\Functional;

use Duyler\Yaml\Exception\FileNotReadableException;
use Duyler\Yaml\Exception\FileNotFoundException;
use Duyler\Yaml\Yaml;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Duyler\Yaml\Exception\AnchorNotFoundException;

use function chmod;
use function file_exists;
use function file_put_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class YamlParseFileTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/duyler-yaml-' . uniqid() . '.yaml';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->path)) {
            unlink($this->path);
        }
    }

    #[Test]
    public function parses_file_content(): void
    {
        file_put_contents($this->path, "a: 1\nb: [x, y]");

        self::assertSame(['a' => 1, 'b' => ['x', 'y']], Yaml::parseFile($this->path));
    }

    #[Test]
    public function parses_all_documents_from_file(): void
    {
        file_put_contents($this->path, "a: 1\n---\nb: 2");

        self::assertSame([['a' => 1], ['b' => 2]], Yaml::parseAllFile($this->path));
    }

    #[Test]
    public function parse_errors_carry_file_name(): void
    {
        file_put_contents($this->path, "a: 1\nb: *missing");

        $this->expectException(AnchorNotFoundException::class);

        try {
            Yaml::parseFile($this->path);
            self::fail('AnchorNotFoundException expected');
        } catch (AnchorNotFoundException $exception) {
            self::assertSame($this->path . ':2:4: Unknown anchor "missing"', $exception->getMessage());
            self::assertSame($this->path, $exception->fileName);
            self::assertSame(2, $exception->lineNumber);

            throw $exception;
        }
    }

    #[Test]
    public function missing_file_throws_file_not_found(): void
    {
        $missing = sys_get_temp_dir() . '/duyler-yaml-missing-' . uniqid() . '.yaml';

        $this->expectException(FileNotFoundException::class);

        try {
            Yaml::parseFile($missing);
            self::fail(FileNotFoundException::class . ' expected');
        } catch (FileNotFoundException $exception) {
            self::assertSame('File "' . $missing . '" not found', $exception->getMessage());
            self::assertSame($missing, $exception->fileName);

            throw $exception;
        }
    }

    #[Test]
    public function url_path_is_rejected_as_file_not_found(): void
    {
        $this->expectException(FileNotFoundException::class);

        try {
            Yaml::parseFile('http://example.com/config.yaml');
            self::fail(FileNotFoundException::class . ' expected');
        } catch (FileNotFoundException $exception) {
            self::assertSame('http://example.com/config.yaml', $exception->fileName);

            throw $exception;
        }
    }

    #[Test]
    public function stream_wrapper_is_rejected_as_file_not_found(): void
    {
        $this->expectException(FileNotFoundException::class);

        Yaml::parseFile('php://input');
    }

    #[Test]
    public function unreadable_file_throws_file_not_readable(): void
    {
        file_put_contents($this->path, 'a: 1');
        chmod($this->path, 0);

        if (true === is_readable($this->path)) {
            self::markTestSkipped('File permissions are not enforced (running as root)');
        }

        $this->expectException(FileNotReadableException::class);

        try {
            Yaml::parseFile($this->path);
            self::fail(FileNotReadableException::class . ' expected');
        } catch (FileNotReadableException $exception) {
            self::assertSame($this->path, $exception->fileName);

            throw $exception;
        }
    }
}
