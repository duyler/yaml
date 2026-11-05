<?php

declare(strict_types=1);

namespace Duyler\Yaml;

use Duyler\Yaml\Contract\TagHandlerInterface;
use InvalidArgumentException;

use function sprintf;

final readonly class ParseConfig
{
    public const int DEFAULT_MAX_DEPTH = 256;
    public const int DEFAULT_MAX_ALIASES = 512;
    public const int MAX_ALLOWED_LIMIT = 1_000_000;

    /**
     * @param array<string, TagHandlerInterface> $tagHandlers
     */
    public function __construct(
        public int $maxDepth = self::DEFAULT_MAX_DEPTH,
        public int $maxAliases = self::DEFAULT_MAX_ALIASES,
        public array $tagHandlers = [],
    ) {
        if ($this->maxDepth < 1 || $this->maxDepth > self::MAX_ALLOWED_LIMIT) {
            throw new InvalidArgumentException(
                sprintf('maxDepth must be between 1 and %d, %d given', self::MAX_ALLOWED_LIMIT, $this->maxDepth),
            );
        }
        if ($this->maxAliases < 1 || $this->maxAliases > self::MAX_ALLOWED_LIMIT) {
            throw new InvalidArgumentException(
                sprintf('maxAliases must be between 1 and %d, %d given', self::MAX_ALLOWED_LIMIT, $this->maxAliases),
            );
        }
    }
}
