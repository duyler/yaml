<?php

declare(strict_types=1);

namespace Duyler\Yaml\Internal\Scanner;

enum ScalarStyle
{
    case Plain;
    case SingleQuoted;
    case DoubleQuoted;
    case Literal;
    case Folded;
}
