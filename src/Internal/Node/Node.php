<?php

declare(strict_types=1);

namespace Duyler\Yaml\Internal\Node;

use Duyler\Yaml\Internal\Scanner\Position;

abstract class Node
{
    public function __construct(
        public readonly Position $position,
    ) {}
}
