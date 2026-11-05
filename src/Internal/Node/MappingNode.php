<?php

declare(strict_types=1);

namespace Duyler\Yaml\Internal\Node;

use Duyler\Yaml\Internal\Scanner\Position;

final class MappingNode extends Node
{
    /** @param list<MappingEntry> $entries */
    public function __construct(
        Position $position,
        public readonly array $entries,
        public readonly ?string $tag = null,
        public readonly ?string $anchor = null,
    ) {
        parent::__construct($position);
    }
}
