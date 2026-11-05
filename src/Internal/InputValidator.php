<?php

declare(strict_types=1);

namespace Duyler\Yaml\Internal;

use Duyler\Yaml\Exception\InvalidEncodingException;

use function count;
use function explode;
use function intdiv;
use function preg_match;
use function str_starts_with;
use function strlen;
use function substr;

final class InputValidator
{
    private const string BOM = "\xEF\xBB\xBF";
    private const int MAX_MULTIBYTE_TAIL = 3;

    public function validate(string $input): string
    {
        if (str_starts_with($input, self::BOM)) {
            $input = substr($input, strlen(self::BOM));
        }

        if (1 === preg_match('//u', $input)) {
            return $input;
        }

        [$line, $column] = $this->locateInvalidByte($input);

        throw new InvalidEncodingException(
            message: 'Invalid UTF-8 input',
            lineNumber: $line,
            column: $column,
        );
    }

    /**
     * @return array{int, int}
     */
    private function locateInvalidByte(string $input): array
    {
        $lines = explode("\n", $input);
        $lineEndOffsets = [];
        $offset = 0;

        foreach ($lines as $index => $line) {
            $offset += strlen($line) + 1;
            $lineEndOffsets[$index] = $offset;
        }

        $total = count($lines);
        $low = 0;
        $high = $total - 1;

        while ($low < $high) {
            $mid = intdiv($low + $high, 2);

            if ($this->isValidSlice(substr($input, 0, $lineEndOffsets[$mid]))) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        return [$low + 1, $this->locateInvalidColumn($lines[$low])];
    }

    private function locateInvalidColumn(string $line): int
    {
        $length = strlen($line);
        $low = 0;
        $high = $length;

        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);

            if ($this->hasValidPrefixNear($line, $mid)) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        return $low + 1;
    }

    private function hasValidPrefixNear(string $line, int $offset): bool
    {
        for ($tail = 0; $tail <= self::MAX_MULTIBYTE_TAIL; $tail++) {
            if ($this->isValidSlice(substr($line, 0, $offset + $tail))) {
                return true;
            }
        }

        return false;
    }

    private function isValidSlice(string $slice): bool
    {
        return 1 === preg_match('//u', $slice);
    }
}
