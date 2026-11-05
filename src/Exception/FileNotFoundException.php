<?php

declare(strict_types=1);

namespace Duyler\Yaml\Exception;

use function sprintf;

final class FileNotFoundException extends YamlException
{
    public function __construct(
        public readonly string $fileName,
    ) {
        parent::__construct(sprintf('File "%s" not found', $fileName));
    }
}
