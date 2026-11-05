<?php

declare(strict_types=1);

namespace Duyler\Yaml\Internal\Scanner;

enum Chomping
{
    case Strip;
    case Clip;
    case Keep;
}
