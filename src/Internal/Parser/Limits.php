<?php

declare(strict_types=1);

namespace Duyler\Yaml\Internal\Parser;

final readonly class Limits
{
    public function __construct(
        public int $maxDepth = 256,
        public int $maxAliases = 512,
    ) {}
}
