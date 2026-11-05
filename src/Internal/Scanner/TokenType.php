<?php

declare(strict_types=1);

namespace Duyler\Yaml\Internal\Scanner;

enum TokenType
{
    case Scalar;
    case Tag;
    case Anchor;
    case Alias;
    case SequenceEntry;
    case MappingValue;
    case FlowSequenceStart;
    case FlowSequenceEnd;
    case FlowMappingStart;
    case FlowMappingEnd;
    case FlowEntry;
    case DocumentStart;
    case DocumentEnd;
    case Directive;
    case ComplexKeyIndicator;
}
