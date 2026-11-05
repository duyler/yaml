<?php

declare(strict_types=1);

namespace Duyler\Yaml\Internal\Scanner;

final readonly class Position
{
    public function __construct(
        public int $line,
        public int $column,
    ) {}
}
