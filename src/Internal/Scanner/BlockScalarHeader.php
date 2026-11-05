<?php

declare(strict_types=1);

namespace Duyler\Yaml\Internal\Scanner;

final readonly class BlockScalarHeader
{
    public function __construct(
        public Chomping $chomping,
        public ?int $explicitIndent,
    ) {}
}
