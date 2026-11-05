<?php

declare(strict_types=1);

namespace Duyler\Yaml\Internal\Node;

use Duyler\Yaml\Internal\Scanner\BlockScalarHeader;
use Duyler\Yaml\Internal\Scanner\Position;
use Duyler\Yaml\Internal\Scanner\ScalarStyle;

final class ScalarNode extends Node
{
    public function __construct(
        Position $position,
        public readonly ScalarStyle $style,
        public readonly string $raw,
        public readonly ?BlockScalarHeader $blockHeader = null,
        public readonly ?string $tag = null,
        public readonly ?string $anchor = null,
    ) {
        parent::__construct($position);
    }
}
