<?php

declare(strict_types=1);

namespace Duyler\Yaml\Internal\Node;

use Duyler\Yaml\Internal\Scanner\Position;

final class DocumentNode extends Node
{
    public function __construct(
        Position $position,
        public readonly Node $root,
        public readonly ?string $version = null,
        /** @var array<string, string> */
        public readonly array $tagPrefixes = [],
    ) {
        parent::__construct($position);
    }
}
