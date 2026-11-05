<?php

declare(strict_types=1);

namespace Duyler\Yaml\Internal\Scanner;

final readonly class Token
{
    public function __construct(
        public TokenType $type,
        public string $value,
        public Position $position,
        public ?ScalarStyle $style = null,
        public ?BlockScalarHeader $blockHeader = null,
    ) {}
}
