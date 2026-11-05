<?php

declare(strict_types=1);

namespace Duyler\Yaml\Internal\Composer;

enum AnchorStatus
{
    case Building;
    case Complete;
}
