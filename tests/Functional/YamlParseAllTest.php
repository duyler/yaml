<?php

declare(strict_types=1);

namespace Duyler\Yaml\Test\Functional;

use Duyler\Yaml\Yaml;
use PHPUnit\Framework\Attributes\Test;

use PHPUnit\Framework\TestCase;
use Duyler\Yaml\Exception\AnchorNotFoundException;

use function str_repeat;

class YamlParseAllTest extends TestCase
{
    #[Test]
    public function parses_every_document_of_a_stream(): void
    {
        $input = "first: 1\n---\nsecond: 2\n---\nthird: 3";

        self::assertSame(
            [['first' => 1], ['second' => 2], ['third' => 3]],
            Yaml::parseAll($input),
        );
    }

    #[Test]
    public function empty_stream_yields_empty_list(): void
    {
        self::assertSame([], Yaml::parseAll(''));
    }

    #[Test]
    public function document_markers_without_content_yield_null_documents(): void
    {
        self::assertSame([null, null], Yaml::parseAll("---\n---\n"));
    }

    #[Test]
    public function anchors_do_not_leak_between_documents(): void
    {
        $input = "a: &v 1\n---\nb: *v";

        $this->expectException(AnchorNotFoundException::class);

        try {
            Yaml::parseAll($input);
            self::fail('AnchorNotFoundException expected');
        } catch (AnchorNotFoundException $exception) {
            self::assertSame('3:4: Unknown anchor "v"', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    public function deployment_counter_resets_between_documents(): void
    {
        $firstAliases = str_repeat('*x, ', 510) . '*x';
        $secondAliases = str_repeat('*y, ', 510) . '*y';
        $input = "a: &x 1\nb: [$firstAliases]\n---\nc: &y 1\nd: [$secondAliases]";

        self::assertCount(2, Yaml::parseAll($input));
    }

    #[Test]
    public function merge_and_tags_compose_in_every_document(): void
    {
        $input = "base: &b {x: 1}\nmerged:\n  <<: *b\n---\ntagged: !!int \"42\"";

        self::assertSame(
            [
                ['base' => ['x' => 1], 'merged' => ['x' => 1]],
                ['tagged' => 42],
            ],
            Yaml::parseAll($input),
        );
    }
}
