<?php

declare(strict_types=1);

namespace Duyler\Yaml;

final readonly class TaggedValue
{
    public function __construct(
        public string $tag,
        public mixed $value,
    ) {}
}
