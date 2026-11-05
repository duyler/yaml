<?php

declare(strict_types=1);

namespace Duyler\Yaml\Internal\Composer;

use Duyler\Yaml\Internal\Node\Node;

final readonly class AnchorEntry
{
    public function __construct(
        public AnchorStatus $status,
        public Node $node,
    ) {}
}
