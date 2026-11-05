<?php

declare(strict_types=1);

namespace Duyler\Yaml\Internal\Node;

use Duyler\Yaml\Internal\Scanner\Position;

final class SequenceNode extends Node
{
    /** @param list<Node> $items */
    public function __construct(
        Position $position,
        public readonly array $items,
        public readonly ?string $tag = null,
        public readonly ?string $anchor = null,
    ) {
        parent::__construct($position);
    }
}
