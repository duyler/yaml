<?php

declare(strict_types=1);

namespace Duyler\Yaml\Exception;

/**
 * @psalm-consistent-constructor
 */
class ParseException extends YamlException
{
    public function __construct(
        string $message,
        public readonly int $lineNumber,
        public readonly int $column,
        public readonly ?string $fileName = null,
    ) {
        parent::__construct(
            (null !== $fileName ? $fileName . ':' : '') . $lineNumber . ':' . $column . ': ' . $message,
        );
    }
}
