<?php

declare(strict_types=1);

namespace Duyler\Yaml\Internal\Composer;

use Duyler\Yaml\Internal\Node\ScalarNode;
use Duyler\Yaml\Internal\Scanner\Chomping;

final readonly class ScalarIndicators
{
    public function __construct(
        public Chomping $chomping = Chomping::Clip,
        public ?int $explicitIndent = null,
    ) {}

    public static function fromNode(ScalarNode $node): self
    {
        $header = $node->blockHeader;

        return new self(
            chomping: $header?->chomping ?? Chomping::Clip,
            explicitIndent: $header?->explicitIndent,
        );
    }
}
