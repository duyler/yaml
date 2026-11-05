<?php

declare(strict_types=1);

namespace Duyler\Yaml\Internal\Node;

use Duyler\Yaml\Internal\Scanner\Position;

final class AliasNode extends Node
{
    public function __construct(
        Position $position,
        public readonly string $name,
    ) {
        parent::__construct($position);
    }
}
