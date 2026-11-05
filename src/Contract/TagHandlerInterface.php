<?php

declare(strict_types=1);

namespace Duyler\Yaml\Contract;

use Duyler\Yaml\TaggedValue;

interface TagHandlerInterface
{
    public function resolve(TaggedValue $value): mixed;
}
