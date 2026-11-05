<?php

declare(strict_types=1);

namespace Duyler\Yaml\Internal\Parser;

final readonly class ParseContext
{
    public function __construct(
        public ?string $file,
        public Limits $limits,
    ) {}
}
