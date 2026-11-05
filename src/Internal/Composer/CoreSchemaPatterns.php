<?php

declare(strict_types=1);

namespace Duyler\Yaml\Internal\Composer;

final class CoreSchemaPatterns
{
    public const string INT_DECIMAL_PATTERN = '/^[-+]?(0|[1-9][0-9]*)$/';
    public const string INT_OCTAL_PATTERN = '/^0o[0-7]+$/';
    public const string INT_HEX_PATTERN = '/^0x[0-9a-fA-F]+$/';
    public const string FLOAT_PATTERN = '/^[-+]?(\.[0-9]+|(0|[1-9][0-9]*)(\.[0-9]*)?)([eE][-+]?[0-9]+)?$/';
    public const string INF_PATTERN = '/^[-+]?\.(inf|Inf|INF)$/';
    public const string NAN_PATTERN = '/^\.(nan|NaN|NAN)$/';
}
