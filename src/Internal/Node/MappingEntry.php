<?php

declare(strict_types=1);

namespace Duyler\Yaml\Internal\Node;

final readonly class MappingEntry
{
    public function __construct(
        public Node $key,
        public Node $value,
    ) {}
}
