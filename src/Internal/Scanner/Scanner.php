<?php

declare(strict_types=1);

namespace Duyler\Yaml\Internal\Scanner;

use Duyler\Yaml\Exception\SyntaxException;
use Duyler\Yaml\Exception\UnsupportedFeatureException;

use function implode;
use function in_array;
use function ltrim;
use function min;
use function rtrim;
use function strcspn;
use function strlen;
use function strspn;
use function substr;

final class Scanner
{
    private const string PLAIN_STOP_CHARS = ":#-[]{}&*!|>\"'?,\t\n";
    private const string CONTROL_CHARS = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0B\x0C\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F";
    private const string NAME_STOP_CHARS = " \t\n,[]{}";
    private const string DOCUMENT_START = '---';
    private const string DOCUMENT_END = '...';

    private string $input = '';
    private int $length = 0;
    private int $offset = 0;
    private int $line = 1;
    private int $lineStart = 0;
    private int $parentIndent = 0;
    private int $flowDepth = 0;
    private ?Position $lastScalarPosition = null;

    /** @return list<Token> */
    public function scan(string $input): array
    {
        $this->input = $input;
        $this->length = strlen($input);
        $this->offset = 0;
        $this->line = 1;
        $this->lineStart = 0;
        $this->parentIndent = 0;
        $this->flowDepth = 0;
        $this->lastScalarPosition = null;

        $tokens = [];
        while ($this->offset < $this->length) {
            if ($this->offset === $this->lineStart) {
                $this->skipIndentation();
                if ($this->offset >= $this->length) {
                    break;
                }
                if ("#" === $this->input[$this->offset]) {
                    $this->skipComment();
                    continue;
                }
                $marker = $this->scanDocumentMarker();
                if (null !== $marker) {
                    $tokens[] = $marker;
                    continue;
                }
                if ("%" === $this->input[$this->offset] && $this->offset === $this->lineStart) {
                    $tokens[] = $this->scanDirective();
                    continue;
                }
            }
            $character = $this->input[$this->offset];
            if ("\n" === $character) {
                $this->consumeLineBreak();
                continue;
            }
            if (" " === $character || "\t" === $character) {
                $this->offset++;
                continue;
            }
            $token = $this->scanToken($character);
            if (null !== $token) {
                $tokens[] = $token;
            }
        }
        if ($this->hasOpenFlow()) {
            throw new SyntaxException(
                message: 'Unterminated flow collection',
                lineNumber: $this->line,
                column: $this->currentColumn(),
            );
        }

        return $tokens;
    }

    private function scanToken(string $character): ?Token
    {
        return match ($character) {
            "#" => $this->skipCommentAndReturnNull(),
            "'" => $this->scanSingleQuoted(),
            '"' => $this->scanDoubleQuoted(),
            "&" => $this->scanAnchor(),
            "*" => $this->scanAlias(),
            "!" => $this->scanTag(),
            "|" => $this->scanBlockScalar(false),
            ">" => $this->scanBlockScalar(true),
            "-" => $this->isBlankOrEndAt($this->offset + 1)
                ? $this->simpleToken(TokenType::SequenceEntry, "-", advance: true, parentIndent: true)
                : $this->scanPlain(),
            ":" => $this->colonEndsAt($this->offset + 1)
                ? $this->scanColon()
                : $this->scanPlain(),
            "?" => $this->isBlankOrEndAt($this->offset + 1)
                ? $this->simpleToken(TokenType::ComplexKeyIndicator, "?", advance: true)
                : $this->scanPlain(),
            "[" => $this->scanFlowEdge(TokenType::FlowSequenceStart, "["),
            "]" => $this->scanFlowEdge(TokenType::FlowSequenceEnd, "]"),
            "{" => $this->scanFlowEdge(TokenType::FlowMappingStart, "{"),
            "}" => $this->scanFlowEdge(TokenType::FlowMappingEnd, "}"),
            "," => $this->simpleToken(TokenType::FlowEntry, ",", advance: true),
            "%" => throw new SyntaxException(
                message: "Character '%' cannot start a token outside the directive position",
                lineNumber: $this->line,
                column: $this->currentColumn(),
            ),
            default => $this->scanPlain(),
        };
    }

    private function skipIndentation(): void
    {
        $this->offset += strspn($this->input, " ", $this->offset);
        if ($this->offset < $this->length && "\t" === $this->input[$this->offset]) {
            throw new SyntaxException(
                message: 'Tab in indentation',
                lineNumber: $this->line,
                column: $this->currentColumn(),
            );
        }
    }

    private function skipComment(): void
    {
        $jump = strcspn($this->input, "\n", $this->offset);
        $body = substr($this->input, $this->offset, $jump);
        $this->assertNoControlChars($body, $this->offset);
        $this->offset += $jump;
    }

    private function skipCommentAndReturnNull(): null
    {
        $this->skipComment();

        return null;
    }

    private function scanDocumentMarker(): ?Token
    {
        if ($this->offset !== $this->lineStart) {
            return null;
        }
        $marker = substr($this->input, $this->offset, 3);
        $type = match ($marker) {
            self::DOCUMENT_START => TokenType::DocumentStart,
            self::DOCUMENT_END => TokenType::DocumentEnd,
            default => null,
        };
        if (null === $type || !$this->isBlankOrEndAt($this->offset + 3)) {
            return null;
        }
        $this->offset += 3;
        $this->parentIndent = 0;

        return new Token(type: $type, value: $marker, position: $this->positionAt($this->offset - 3));
    }

    private function scanDirective(): Token
    {
        $position = $this->positionAt($this->offset);
        $this->offset++;
        $jump = strcspn($this->input, "\n", $this->offset);
        $body = substr($this->input, $this->offset, $jump);
        $this->assertNoControlChars($body, $this->offset);
        $this->offset += $jump;
        $this->parentIndent = 0;

        return new Token(type: TokenType::Directive, value: "%" . $body, position: $position);
    }

    private function scanColon(): Token
    {
        $column = $this->offset - $this->lineStart;
        $token = $this->simpleToken(TokenType::MappingValue, ":", advance: true);
        if (0 === $this->flowDepth) {
            $this->parentIndent = null !== $this->lastScalarPosition && $this->lastScalarPosition->line === $this->line
                ? $this->lastScalarPosition->column - 1
                : $column;
        }

        return $token;
    }

    private function scanFlowEdge(TokenType $type, string $character): Token
    {
        if ($this->isFlowOpen($type)) {
            $this->flowDepth++;
        } else {
            $this->flowDepth--;
        }

        return $this->simpleToken($type, $character, advance: true);
    }

    private function isFlowOpen(TokenType $type): bool
    {
        return TokenType::FlowSequenceStart === $type || TokenType::FlowMappingStart === $type;
    }

    private function hasOpenFlow(): bool
    {
        return $this->flowDepth > 0;
    }

    private function scanAnchor(): Token
    {
        return $this->scanName(TokenType::Anchor, "&", "Anchor name is empty");
    }

    private function scanAlias(): Token
    {
        return $this->scanName(TokenType::Alias, "*", "Alias name is empty");
    }

    private function scanTag(): Token
    {
        $position = $this->positionAt($this->offset);
        $this->offset++;
        if ($this->offset < $this->length && "<" === $this->input[$this->offset]) {
            $this->offset++;
            $jump = strcspn($this->input, ">\n", $this->offset);
            $closing = $this->offset + $jump < $this->length && ">" === $this->input[$this->offset + $jump];
            if (!$closing) {
                $scanEnd = min($this->offset + $jump, $this->length);
                throw new SyntaxException(
                    message: 'Unterminated verbatim tag',
                    lineNumber: $this->line,
                    column: $scanEnd - $this->lineStart + 1,
                );
            }
            $verbatim = substr($this->input, $this->offset, $jump);
            $this->assertNoControlChars($verbatim, $this->offset);
            $this->offset += $jump + 1;

            return new Token(type: TokenType::Tag, value: "!<" . $verbatim . ">", position: $position);
        }
        $jump = strcspn($this->input, self::NAME_STOP_CHARS, $this->offset);
        $suffix = substr($this->input, $this->offset, $jump);
        $this->assertNoControlChars($suffix, $this->offset);
        $this->offset += $jump;

        return new Token(type: TokenType::Tag, value: "!" . $suffix, position: $position);
    }

    private function scanName(TokenType $type, string $prefix, string $emptyMessage): Token
    {
        $position = $this->positionAt($this->offset);
        $this->offset++;
        $jump = strcspn($this->input, self::NAME_STOP_CHARS, $this->offset);
        if (0 === $jump) {
            throw new SyntaxException(
                message: $emptyMessage,
                lineNumber: $this->line,
                column: $this->currentColumn(),
            );
        }
        $name = substr($this->input, $this->offset, $jump);
        $this->assertNoControlChars($name, $this->offset);
        $this->offset += $jump;

        return new Token(type: $type, value: $prefix . $name, position: $position);
    }

    private function scanSingleQuoted(): Token
    {
        $position = $this->positionAt($this->offset);
        $this->offset++;
        $raw = "";
        while (true) {
            $jump = strcspn($this->input, "'\n", $this->offset);
            if ($jump > 0) {
                $segment = rtrim(substr($this->input, $this->offset, $jump), "\r");
                $this->assertNoControlChars($segment, $this->offset);
                $raw .= $segment;
                $this->offset += $jump;
            }
            if ($this->offset >= $this->length || "\n" === $this->input[$this->offset]) {
                throw $this->multiLineQuoteError($position);
            }
            $this->offset++;
            if ($this->offset < $this->length && "'" === $this->input[$this->offset]) {
                $raw .= "''";
                $this->offset++;
                continue;
            }
            break;
        }

        return new Token(
            type: TokenType::Scalar,
            value: "'" . $raw . "'",
            position: $position,
            style: ScalarStyle::SingleQuoted,
        );
    }

    private function scanDoubleQuoted(): Token
    {
        $position = $this->positionAt($this->offset);
        $this->offset++;
        $raw = "";
        while (true) {
            $jump = strcspn($this->input, "\\\n\"", $this->offset);
            if ($jump > 0) {
                $segment = rtrim(substr($this->input, $this->offset, $jump), "\r");
                $this->assertNoControlChars($segment, $this->offset);
                $raw .= $segment;
                $this->offset += $jump;
            }
            if ($this->offset >= $this->length || "\n" === $this->input[$this->offset]) {
                throw $this->multiLineQuoteError($position);
            }
            $character = $this->input[$this->offset];
            if ('"' === $character) {
                $this->offset++;
                break;
            }
            if ($this->offset + 1 >= $this->length || "\n" === $this->input[$this->offset + 1]) {
                throw $this->multiLineQuoteError($position);
            }
            $raw .= $character . $this->input[$this->offset + 1];
            $this->offset += 2;
        }

        return new Token(
            type: TokenType::Scalar,
            value: '"' . $raw . '"',
            position: $position,
            style: ScalarStyle::DoubleQuoted,
        );
    }

    private function multiLineQuoteError(Position $position): UnsupportedFeatureException
    {
        return new UnsupportedFeatureException(
            message: 'Multi-line quoted scalars are not supported',
            lineNumber: $position->line,
            column: $position->column,
        );
    }

    private function scanBlockScalar(bool $folded): Token
    {
        $position = $this->positionAt($this->offset);
        $this->offset++;
        $chomping = Chomping::Clip;
        $chompingSet = false;
        $explicitIndent = null;
        while ($this->offset < $this->length) {
            $character = $this->input[$this->offset];
            if (("-" === $character || "+" === $character) && !$chompingSet) {
                $chomping = "-" === $character ? Chomping::Strip : Chomping::Keep;
                $chompingSet = true;
                $this->offset++;
                continue;
            }
            if (null === $explicitIndent && $character >= "1" && $character <= "9") {
                $explicitIndent = (int) $character;
                $this->offset++;
                continue;
            }
            break;
        }
        $this->offset += strspn($this->input, " ", $this->offset);
        if ($this->offset < $this->length && "#" === $this->input[$this->offset]) {
            $this->skipComment();
        }
        if ($this->offset >= $this->length) {
            return new Token(
                type: TokenType::Scalar,
                value: "",
                position: $position,
                style: $folded ? ScalarStyle::Folded : ScalarStyle::Literal,
                blockHeader: new BlockScalarHeader(
                    chomping: $chomping,
                    explicitIndent: $explicitIndent,
                ),
            );
        }
        if ("\n" !== $this->input[$this->offset]) {
            throw new SyntaxException(
                message: 'Invalid block scalar header',
                lineNumber: $this->line,
                column: $this->currentColumn(),
            );
        }
        $this->consumeLineBreak();
        $contentIndent = null !== $explicitIndent ? $this->parentIndent + $explicitIndent : null;
        $lines = [];
        while (true) {
            if ($this->offset >= $this->length) {
                break;
            }
            $lineEnd = strcspn($this->input, "\n", $this->offset);
            $lineText = substr($this->input, $this->offset, $lineEnd);
            $content = ltrim($lineText, " ");
            $leading = strlen($lineText) - strlen($content);
            if ("" === $content) {
                $lines[] = "";
                $this->offset += $lineEnd;
                if ($this->offset < $this->length) {
                    $this->consumeLineBreak();
                    continue;
                }
                break;
            }
            if (null === $contentIndent) {
                if ($leading <= $this->parentIndent) {
                    $lines = [];
                    break;
                }
                $contentIndent = $leading;
            } elseif ($leading < $contentIndent) {
                if ($leading > $this->parentIndent) {
                    throw new SyntaxException(
                        message: 'Block scalar content is not properly indented',
                        lineNumber: $this->line,
                        column: $this->offset + $leading - $this->lineStart + 1,
                    );
                }
                break;
            }
            $contentLine = substr($lineText, $contentIndent);
            $this->assertNoControlChars($contentLine, $this->offset + $contentIndent);
            $lines[] = $contentLine;
            $this->offset += $lineEnd;
            if ($this->offset < $this->length) {
                $this->consumeLineBreak();
                continue;
            }
            break;
        }

        return new Token(
            type: TokenType::Scalar,
            value: implode("\n", $lines),
            position: $position,
            style: $folded ? ScalarStyle::Folded : ScalarStyle::Literal,
            blockHeader: new BlockScalarHeader(
                chomping: $chomping,
                explicitIndent: $explicitIndent,
            ),
        );
    }

    private function scanPlain(): Token
    {
        $position = $this->positionAt($this->offset);
        $raw = "";
        while ($this->offset < $this->length) {
            $jump = strcspn($this->input, self::PLAIN_STOP_CHARS, $this->offset);
            if ($jump > 0) {
                $segment = rtrim(substr($this->input, $this->offset, $jump), "\r");
                $this->assertNoControlChars($segment, $this->offset);
                $raw .= $segment;
                $this->offset += $jump;
            }
            if ($this->offset >= $this->length) {
                break;
            }
            $character = $this->input[$this->offset];
            if ("\n" === $character) {
                if ($this->advancePlainContinuation($raw)) {
                    continue;
                }
                $raw = rtrim($raw, " \t\r");
                break;
            }
            if ("#" === $character && $this->isBlankPreceded($raw)) {
                break;
            }
            if ("-" === $character) {
                $raw .= "-";
                $this->offset++;
                continue;
            }
            if (":" === $character) {
                if ($this->colonEndsAt($this->offset + 1)) {
                    break;
                }
                $raw .= ":";
                $this->offset++;
                continue;
            }
            if ("?" === $character) {
                if ($this->isBlankOrEndAt($this->offset + 1)) {
                    break;
                }
                $raw .= "?";
                $this->offset++;
                continue;
            }
            if ($this->isPlainBoundary($character)) {
                break;
            }
            $raw .= $character;
            $this->offset++;
        }
        $this->lastScalarPosition = $position;

        return new Token(
            type: TokenType::Scalar,
            value: rtrim($raw, " \t\r"),
            position: $position,
            style: ScalarStyle::Plain,
        );
    }

    private function advancePlainContinuation(string &$raw): bool
    {
        if ($this->flowDepth > 0) {
            return false;
        }
        $lookOffset = $this->offset + 1;
        $lookLine = $this->line + 1;
        $lookLineStart = $lookOffset;
        $breaks = "";
        while (true) {
            if ($lookOffset >= $this->length) {
                return false;
            }
            $lineEnd = strcspn($this->input, "\n", $lookOffset);
            $lineText = substr($this->input, $lookOffset, $lineEnd);
            if (str_ends_with($lineText, "\r")) {
                $lineText = substr($lineText, 0, -1);
            }
            $content = ltrim($lineText, " ");
            $leading = strlen($lineText) - strlen($content);
            if ("" !== $content && "\t" === $content[0]) {
                throw new SyntaxException(
                    message: 'Tab in indentation',
                    lineNumber: $lookLine,
                    column: $lookOffset + $leading - $lookLineStart + 1,
                );
            }
            if ("" === $content) {
                $lookOffset += $lineEnd;
                if ($lookOffset >= $this->length) {
                    return false;
                }
                $lookOffset++;
                $lookLine++;
                $lookLineStart = $lookOffset;
                $breaks .= "\n";
                continue;
            }
            if ($leading <= $this->parentIndent) {
                return false;
            }
            $first = $content[0];
            if ("#" === $first) {
                return false;
            }
            if (str_contains($content, ': ')) {
                return false;
            }
            if ((":" === $first || "?" === $first || "-" === $first)
                && $this->isBlankOrEndAt($lookOffset + $leading + 1)) {
                return false;
            }
            $fold = '' === $breaks ? " " : $breaks;
            $raw = rtrim($raw, " \t\r");
            $raw .= $fold;
            $this->offset = $lookOffset + $leading;
            $this->line = $lookLine;
            $this->lineStart = $lookLineStart;

            return true;
        }
    }

    private function isPlainBoundary(string $character): bool
    {
        if ('"' === $character || "'" === $character || "&" === $character
            || "*" === $character || "!" === $character || "|" === $character || ">" === $character) {
            return true;
        }

        return $this->isFlowBoundary($character);
    }

    private function isFlowBoundary(string $character): bool
    {
        if ($this->flowDepth > 0) {
            return in_array($character, [",", "[", "]", "{", "}"], true);
        }

        return false;
    }

    private function colonEndsAt(int $at): bool
    {
        if ($at >= $this->length) {
            return true;
        }
        $next = $this->input[$at];
        if (in_array($next, ["\n", " ", "\t"], true)) {
            return true;
        }

        return $this->flowDepth > 0 && in_array($next, [",", "[", "]", "{", "}"], true);
    }

    private function isBlankOrEndAt(int $at): bool
    {
        if ($at >= $this->length) {
            return true;
        }

        return in_array($this->input[$at], [" ", "\t", "\n"], true);
    }

    private function isBlankPreceded(string $raw): bool
    {
        return "" === $raw || in_array($raw[strlen($raw) - 1], [" ", "\t", "\n"], true);
    }

    private function simpleToken(TokenType $type, string $value, bool $advance, bool $parentIndent = false): Token
    {
        $column = $this->offset - $this->lineStart;
        $position = $this->positionAt($this->offset);
        if ($advance) {
            $this->offset++;
        }
        if ($parentIndent) {
            $this->parentIndent = $column;
        }

        return new Token(type: $type, value: $value, position: $position);
    }

    private function positionAt(int $offset): Position
    {
        return new Position(line: $this->line, column: $offset - $this->lineStart + 1);
    }

    private function currentColumn(): int
    {
        return $this->offset - $this->lineStart + 1;
    }

    private function consumeLineBreak(): void
    {
        $this->offset++;
        $this->line++;
        $this->lineStart = $this->offset;
    }

    private function assertNoControlChars(string $segment, int $segmentOffset): void
    {
        $hit = strcspn($segment, self::CONTROL_CHARS);
        if ($hit < strlen($segment)) {
            throw new SyntaxException(
                message: 'Raw control character is not allowed',
                lineNumber: $this->line,
                column: $segmentOffset + $hit - $this->lineStart + 1,
            );
        }
    }
}
