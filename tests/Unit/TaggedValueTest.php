<?php

declare(strict_types=1);

namespace Duyler\Yaml\Test\Unit;

use Duyler\Yaml\TaggedValue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Error;

class TaggedValueTest extends TestCase
{
    #[Test]
    public function holds_tag_and_value(): void
    {
        $tagged = new TaggedValue(tag: '!custom', value: [1, 2]);

        self::assertSame('!custom', $tagged->tag);
        self::assertSame([1, 2], $tagged->value);
    }

    #[Test]
    public function accepts_null_value(): void
    {
        $tagged = new TaggedValue(tag: '!custom', value: null);

        self::assertNull($tagged->value);
    }

    #[Test]
    public function is_readonly(): void
    {
        $tagged = new TaggedValue(tag: '!custom', value: 'v');

        $this->expectException(Error::class);

        $tagged->tag = '!other';
    }
}
