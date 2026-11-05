<?php

declare(strict_types=1);

namespace Duyler\Yaml\Exception;

use function sprintf;

final class FileNotReadableException extends YamlException
{
    public function __construct(
        public readonly string $fileName,
    ) {
        parent::__construct(sprintf('File "%s" is not readable', $fileName));
    }
}
