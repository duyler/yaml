<?php

declare(strict_types=1);

namespace Duyler\Yaml\Internal\Parser;

use Duyler\Yaml\Exception\DepthLimitExceededException;
use Duyler\Yaml\Exception\DirectiveException;
use Duyler\Yaml\Exception\SyntaxException;
use Duyler\Yaml\Exception\UnsupportedFeatureException;
use Duyler\Yaml\Exception\VersionException;
use Duyler\Yaml\Internal\Node\AliasNode;
use Duyler\Yaml\Internal\Node\DocumentNode;
use Duyler\Yaml\Internal\Node\MappingEntry;
use Duyler\Yaml\Internal\Node\MappingNode;
use Duyler\Yaml\Internal\Node\Node;
use Duyler\Yaml\Internal\Node\ScalarNode;
use Duyler\Yaml\Internal\Node\SequenceNode;
use Duyler\Yaml\Internal\Scanner\Position;
use Duyler\Yaml\Internal\Scanner\ScalarStyle;
use Duyler\Yaml\Internal\Scanner\Token;
use Duyler\Yaml\Internal\Scanner\TokenType;

use function count;
use function explode;
use function in_array;
use function preg_match;
use function str_ends_with;
use function str_starts_with;
use function strpos;
use function substr;
use function strlen;
use function trim;

final class Parser
{
    /** @var list<Token> */
    private array $tokens = [];
    private int $length = 0;
    private int $cursor = 0;
    private int $depth = 0;
    private int $maxDepth = 256;
    private ?string $pendingTag = null;
    private ?string $pendingAnchor = null;
    private ?string $version = null;

    /** @var array<string, string> */
    private array $tagPrefixes = [];

    /** @param list<Token> $tokens @return list<DocumentNode> */
    public function parse(array $tokens, ParseContext $context): array
    {
        $this->tokens = $tokens;
        $this->length = count($tokens);
        $this->cursor = 0;
        $this->depth = 0;
        $this->maxDepth = $context->limits->maxDepth;
        $this->pendingTag = null;
        $this->pendingAnchor = null;
        $this->version = null;
        $this->tagPrefixes = [];

        $documents = [];
        $documentRequired = false;
        while ($this->cursor < $this->length) {
            $token = $this->current();
            if (TokenType::DocumentEnd === $token->type) {
                $this->cursor++;
                $documentRequired = true;
                continue;
            }
            if (TokenType::Directive === $token->type) {
                $documents[] = $this->parseDirectedDocument();
                $documentRequired = false;
                continue;
            }
            if (TokenType::DocumentStart === $token->type) {
                $this->cursor++;
            } elseif ($documentRequired) {
                throw new SyntaxException(
                    message: 'Content after document end marker must be preceded by a document start marker',
                    lineNumber: $token->position->line,
                    column: $token->position->column,
                );
            }
            $documentRequired = false;
            $documents[] = $this->parseDocument($token->position);
        }

        return $documents;
    }

    private function parseDirectedDocument(): DocumentNode
    {
        $lastDirective = $this->current();
        while ($this->cursor < $this->length && TokenType::Directive === $this->current()->type) {
            $lastDirective = $this->current();
            $this->applyDirective($lastDirective);
            $this->cursor++;
        }
        $start = $this->cursor < $this->length ? $this->current() : null;
        if (null === $start || TokenType::DocumentStart !== $start->type) {
            throw new DirectiveException(
                message: 'Directives must be followed by a document start marker',
                lineNumber: $lastDirective->position->line,
                column: $lastDirective->position->column,
            );
        }
        $this->cursor++;

        return $this->parseDocument($start->position);
    }

    private function parseDocument(Position $startPosition): DocumentNode
    {
        $root = $this->parseDocumentRoot();
        $this->assertDocumentTerminated();
        $document = new DocumentNode(
            position: $startPosition,
            root: $root,
            version: $this->version,
            tagPrefixes: $this->tagPrefixes,
        );
        $this->version = null;
        $this->tagPrefixes = [];

        return $document;
    }

    private function assertDocumentTerminated(): void
    {
        if ($this->cursor >= $this->length) {
            return;
        }
        while ($this->cursor < $this->length) {
            $token = $this->current();
            if (in_array($token->type, [TokenType::DocumentStart, TokenType::DocumentEnd], true)) {
                return;
            }
            if (TokenType::Directive === $token->type) {
                throw new DirectiveException(
                    message: 'Directive must not appear after document content',
                    lineNumber: $token->position->line,
                    column: $token->position->column,
                );
            }
            throw new SyntaxException(
                message: 'Unexpected content after document root node',
                lineNumber: $token->position->line,
                column: $token->position->column,
            );
        }
    }

    private function parseDocumentRoot(): Node
    {
        if ($this->cursor >= $this->length) {
            return $this->nullNode($this->lastPosition());
        }
        $token = $this->current();
        if (TokenType::Directive === $token->type) {
            throw new DirectiveException(
                message: 'Directive must precede the document start marker',
                lineNumber: $token->position->line,
                column: $token->position->column,
            );
        }
        if (in_array($token->type, [TokenType::DocumentStart, TokenType::DocumentEnd], true)) {
            return $this->nullNode($token->position);
        }

        return $this->parseBlockNode();
    }

    private function applyDirective(Token $directive): void
    {
        $parts = $this->directiveParts($directive);
        $name = $parts[0] ?? '';
        if ('YAML' === $name) {
            $this->applyYamlDirective($directive, $parts);

            return;
        }
        if ('TAG' === $name) {
            $this->applyTagDirective($directive, $parts);

            return;
        }
        throw new DirectiveException(
            message: 'Unknown directive "%' . $name . '"',
            lineNumber: $directive->position->line,
            column: $directive->position->column,
        );
    }

    /** @return list<string> */
    private function directiveParts(Token $directive): array
    {
        $parts = [];
        foreach (explode(' ', trim(substr($directive->value, 1))) as $part) {
            if ('' === $part) {
                continue;
            }
            if (str_starts_with($part, '#')) {
                break;
            }
            $parts[] = $part;
        }

        return $parts;
    }

    /** @param list<string> $parts */
    private function applyYamlDirective(Token $directive, array $parts): void
    {
        if (null !== $this->version) {
            throw new DirectiveException(
                message: 'Duplicate %YAML directive',
                lineNumber: $directive->position->line,
                column: $directive->position->column,
            );
        }
        $version = $parts[1] ?? '';
        if (2 !== count($parts) || 1 !== preg_match('/^\d+\.\d+(?:\.\d+)?$/', $version)) {
            throw new VersionException(
                message: 'Invalid %YAML directive',
                lineNumber: $directive->position->line,
                column: $directive->position->column,
            );
        }
        if (1 !== preg_match('/^1\.2(?:\.\d+)?$/', $version)) {
            throw new VersionException(
                message: 'Unsupported YAML version "' . $version . '"',
                lineNumber: $directive->position->line,
                column: $directive->position->column,
            );
        }
        $this->version = $version;
    }

    /** @param list<string> $parts */
    private function applyTagDirective(Token $directive, array $parts): void
    {
        $handle = $parts[1] ?? '';
        $prefix = $parts[2] ?? '';
        if (3 !== count($parts)
            || !str_starts_with($handle, '!')
            || (strlen($handle) > 1 && !str_ends_with($handle, '!'))) {
            throw new DirectiveException(
                message: 'Invalid %TAG directive',
                lineNumber: $directive->position->line,
                column: $directive->position->column,
            );
        }
        if (isset($this->tagPrefixes[$handle])) {
            throw new DirectiveException(
                message: 'Duplicate tag handle "' . $handle . '"',
                lineNumber: $directive->position->line,
                column: $directive->position->column,
            );
        }
        $this->tagPrefixes[$handle] = $prefix;
    }

    private function expandTag(string $tag): string
    {
        if (str_starts_with($tag, '!<')) {
            return $tag;
        }
        $secondBang = strpos($tag, '!', 1);
        $handleEnd = false === $secondBang ? 0 : $secondBang;
        $handle = substr($tag, 0, $handleEnd + 1);
        if (isset($this->tagPrefixes[$handle])) {
            return $this->tagPrefixes[$handle] . substr($tag, $handleEnd + 1);
        }
        if (str_starts_with($tag, '!!')) {
            return 'tag:yaml.org,2002:' . substr($tag, 2);
        }

        return $tag;
    }

    private function parseBlockNode(?int $attributesStartOverride = null): Node
    {
        $this->enterNode();
        try {
            $attributesStart = $attributesStartOverride ?? $this->cursor;
            $this->collectAttributes();
            if ($this->cursor >= $this->length) {
                return $this->nullNode($this->lastPosition());
            }
            $token = $this->current();
            $hasPending = null !== $this->pendingTag || null !== $this->pendingAnchor;
            $attributesBaseline = $this->attributesBaseline($attributesStart, $token);
            $nodeColumn = $this->zeroColumn($token);
            $tagOnCollection = $hasPending && null !== $this->pendingTag
                && $this->tagOutsideKeyLine($attributesStart, $token)
                && $this->isCollectionOrLocalTag($this->pendingTag);
            if (TokenType::ComplexKeyIndicator === $token->type) {
                throw new SyntaxException(
                    message: 'Explicit complex keys are not supported',
                    lineNumber: $token->position->line,
                    column: $token->position->column,
                );
            }
            if (TokenType::SequenceEntry === $token->type) {
                return $this->parseSequence($nodeColumn);
            }
            if (TokenType::Scalar === $token->type && $this->isMappingKey($token)) {
                $looseAlignment = null !== $attributesBaseline;
                $keyColumn = $attributesBaseline ?? $nodeColumn;

                return $this->parseMapping($keyColumn, $tagOnCollection, $looseAlignment);
            }

            return $this->parseSingleNode($token);
        } finally {
            $this->leaveNode();
        }
    }

    private function attributesBaseline(int $attributesStart, Token $keyToken): ?int
    {
        for ($index = $attributesStart; $index < $this->cursor; $index++) {
            $position = $this->tokens[$index]->position;
            if ($position->line === $keyToken->position->line) {
                return $position->column - 1;
            }
        }

        return null;
    }

    private function tagOnKeyLine(int $attributesStart, Token $keyToken): bool
    {
        for ($index = $attributesStart; $index < $this->cursor; $index++) {
            $token = $this->tokens[$index];
            if (TokenType::Tag === $token->type && $token->position->line === $keyToken->position->line) {
                return true;
            }
        }

        return false;
    }

    private function tagOutsideKeyLine(int $attributesStart, Token $keyToken): bool
    {
        for ($index = $attributesStart; $index < $this->cursor; $index++) {
            $token = $this->tokens[$index];
            if (TokenType::Tag !== $token->type) {
                continue;
            }
            if ($token->position->line !== $keyToken->position->line) {
                return true;
            }
        }

        return false;
    }

    private function isCollectionOrLocalTag(string $tag): bool
    {
        if (str_starts_with($tag, '!')) {
            return true;
        }

        return in_array($tag, [
            'tag:yaml.org,2002:seq',
            'tag:yaml.org,2002:map',
            'tag:yaml.org,2002:merge',
        ], true);
    }

    private function parseSingleNode(Token $token): Node
    {
        if (TokenType::Scalar === $token->type) {
            $this->cursor++;

            return $this->scalarNode($token);
        }
        if (TokenType::Alias === $token->type) {
            $this->cursor++;

            return $this->aliasNode($token);
        }

        return match ($token->type) {
            TokenType::FlowSequenceStart => $this->parseFlowSequence(),
            TokenType::FlowMappingStart => $this->parseFlowMapping(),
            default => throw new SyntaxException(
                message: 'Unexpected token',
                lineNumber: $token->position->line,
                column: $token->position->column,
            ),
        };
    }

    private function parseSequence(int $entryColumn): Node
    {
        $resolvedAnchor = $this->consumePendingAnchor();
        $resolvedTag = $this->consumePendingTag();
        $position = $this->current()->position;
        $tag = $resolvedTag;
        $items = [];
        while ($this->cursor < $this->length) {
            $token = $this->current();
            if (TokenType::SequenceEntry !== $token->type) {
                break;
            }
            $column = $this->zeroColumn($token);
            if ($column < $entryColumn) {
                break;
            }
            if ($column > $entryColumn) {
                throw new SyntaxException(
                    message: 'Invalid sequence entry indentation',
                    lineNumber: $token->position->line,
                    column: $token->position->column,
                );
            }
            $this->cursor++;
            $items[] = $this->parseSequenceEntryValue($token, $entryColumn);
        }

        return new SequenceNode(
            position: $position,
            items: $items,
            tag: $resolvedTag,
            anchor: $resolvedAnchor,
        );
    }

    private function parseMapping(int $keyColumn, bool $tagOnMapping = false, bool $looseAlignment = false): Node
    {
        $resolvedTag = $tagOnMapping ? $this->consumePendingTag() : null;
        $resolvedAnchor = $this->consumePendingAnchor();
        $position = $this->current()->position;
        $entries = [];
        while ($this->cursor < $this->length) {
            $token = $this->current();
            if (TokenType::Scalar !== $token->type) {
                break;
            }
            $tokenColumn = $this->zeroColumn($token);
            if ($looseAlignment) {
                if ([] !== $entries) {
                    if ($tokenColumn < $keyColumn) {
                        break;
                    }
                    if ($tokenColumn > $keyColumn) {
                        throw new SyntaxException(
                            message: 'Invalid mapping key indentation',
                            lineNumber: $token->position->line,
                            column: $token->position->column,
                        );
                    }
                }
            } elseif ($tokenColumn !== $keyColumn) {
                break;
            }
            if (!$this->isMappingKey($token)) {
                break;
            }
            $keyNode = $this->scalarNode($token);
            $this->cursor++;
            $valueToken = $this->current();
            $this->cursor++;
            $value = $this->parseMappingEntryValue($keyColumn, $valueToken);
            $entries[] = new MappingEntry(key: $keyNode, value: $value);
        }

        return new MappingNode(
            position: $position,
            entries: $entries,
            tag: $tagOnMapping ? $resolvedTag : $this->consumePendingTag(),
            anchor: $resolvedAnchor,
        );
    }

    private function parseSequenceEntryValue(Token $entryToken, int $entryColumn): Node
    {
        $attributesStart = $this->cursor;
        $this->collectAttributes();
        if ($this->cursor >= $this->length) {
            return $this->nullNode($entryToken->position);
        }
        $next = $this->current();
        if ($next->position->line === $entryToken->position->line) {
            if (TokenType::SequenceEntry === $next->type
                || (TokenType::Scalar === $next->type && $this->isMappingKey($next))) {
                throw $this->compactCollectionError($entryToken);
            }
            if (TokenType::ComplexKeyIndicator === $next->type) {
                throw new SyntaxException(
                    message: 'Explicit complex keys are not supported',
                    lineNumber: $next->position->line,
                    column: $next->position->column,
                );
            }

            return $this->parseInlineNode($this->current());
        }
        $column = $this->zeroColumn($next);
        if (TokenType::SequenceEntry === $next->type) {
            if ($column > $entryColumn) {
                $this->enterNode();
                try {
                    return $this->parseSequence($column);
                } finally {
                    $this->leaveNode();
                }
            }

            return $this->nullNode($entryToken->position);
        }
        if ($column > $entryColumn) {
            return $this->parseBlockNode($attributesStart);
        }

        return $this->nullNode($entryToken->position);
    }

    private function parseMappingEntryValue(int $keyColumn, Token $valueToken): Node
    {
        $attributesStart = $this->cursor;
        $this->collectAttributes();
        if ($this->cursor >= $this->length) {
            return $this->nullNode($valueToken->position);
        }
        $next = $this->current();
        if ($next->position->line === $valueToken->position->line) {
            if (TokenType::SequenceEntry === $next->type) {
                throw new SyntaxException(
                    message: 'Sequence entry is not allowed on a mapping value line',
                    lineNumber: $next->position->line,
                    column: $next->position->column,
                );
            }
            if (TokenType::ComplexKeyIndicator === $next->type) {
                throw new SyntaxException(
                    message: 'Explicit complex keys are not supported',
                    lineNumber: $next->position->line,
                    column: $next->position->column,
                );
            }

            return $this->parseInlineNode($this->current());
        }
        $column = $this->zeroColumn($next);
        if (TokenType::SequenceEntry === $next->type) {
            if ($column >= $keyColumn) {
                $this->enterNode();
                try {
                    return $this->parseSequence($column);
                } finally {
                    $this->leaveNode();
                }
            }

            return $this->nullNode($valueToken->position);
        }
        if (TokenType::Scalar === $next->type && $this->isMappingKey($next)) {
            if ($column > $keyColumn) {
                $attributesBaseline = $this->attributesBaseline($attributesStart, $next);
                $looseAlignment = null !== $attributesBaseline;
                $nestedKeyColumn = $attributesBaseline ?? $column;
                $tagOnMapping = null !== $this->pendingTag && !$this->tagOnKeyLine($attributesStart, $next);
                $this->enterNode();
                try {
                    return $this->parseMapping($nestedKeyColumn, tagOnMapping: $tagOnMapping, looseAlignment: $looseAlignment);
                } finally {
                    $this->leaveNode();
                }
            }

            return $this->nullNode($valueToken->position);
        }
        if ($column > $keyColumn) {
            return $this->parseBlockNode();
        }

        return $this->nullNode($valueToken->position);
    }

    private function parseInlineNode(Token $token): Node
    {
        $this->enterNode();
        try {
            return $this->parseSingleNode($token);
        } finally {
            $this->leaveNode();
        }
    }

    private function parseFlowSequence(): Node
    {
        $resolvedTag = $this->consumePendingTag();
        $resolvedAnchor = $this->consumePendingAnchor();
        $position = $this->current()->position;
        $this->cursor++;
        $items = [];
        while (true) {
            if ($this->cursor >= $this->length) {
                throw $this->unterminatedFlowError();
            }
            $token = $this->current();
            if (TokenType::FlowSequenceEnd === $token->type) {
                $this->cursor++;
                break;
            }
            if (TokenType::FlowEntry === $token->type) {
                throw $this->trailingCommaError($token);
            }
            $items[] = $this->parseFlowItem(TokenType::FlowSequenceEnd);
            if ($this->cursor >= $this->length) {
                throw $this->unterminatedFlowError();
            }
            if (TokenType::FlowEntry === $this->current()->type) {
                $comma = $this->current();
                $this->cursor++;
                if ($this->cursor >= $this->length) {
                    throw $this->trailingCommaError($comma);
                }
                $after = $this->current();
                if (TokenType::FlowEntry === $after->type) {
                    throw $this->trailingCommaError($comma);
                }
                if (TokenType::FlowSequenceEnd === $after->type) {
                    $this->cursor++;
                    break;
                }
                continue;
            }
            if (TokenType::FlowSequenceEnd === $this->current()->type) {
                $this->cursor++;
                break;
            }
            throw $this->unexpectedFlowTokenError($this->current());
        }

        return new SequenceNode(
            position: $position,
            items: $items,
            tag: $resolvedTag,
            anchor: $resolvedAnchor,
        );
    }

    private function parseFlowMapping(): Node
    {
        $resolvedTag = $this->consumePendingTag();
        $resolvedAnchor = $this->consumePendingAnchor();
        $position = $this->current()->position;
        $this->cursor++;
        $entries = [];
        while (true) {
            if ($this->cursor >= $this->length) {
                throw $this->unterminatedFlowError();
            }
            $token = $this->current();
            if (TokenType::FlowMappingEnd === $token->type) {
                $this->cursor++;
                break;
            }
            if (TokenType::FlowEntry === $token->type) {
                throw $this->trailingCommaError($token);
            }
            $keyNode = $this->parseFlowNode();
            if ($this->cursor >= $this->length) {
                throw $this->unterminatedFlowError();
            }
            if (TokenType::MappingValue !== $this->current()->type) {
                throw new SyntaxException(
                    message: 'Expected mapping value after flow mapping key',
                    lineNumber: $this->current()->position->line,
                    column: $this->current()->position->column,
                );
            }
            $this->cursor++;
            $value = $this->parseFlowMappingValue($this->previous()->position);
            $entries[] = new MappingEntry(key: $keyNode, value: $value);
            if ($this->cursor >= $this->length) {
                throw $this->unterminatedFlowError();
            }
            if (TokenType::FlowEntry === $this->current()->type) {
                $comma = $this->current();
                $this->cursor++;
                if ($this->cursor >= $this->length) {
                    throw $this->trailingCommaError($comma);
                }
                $after = $this->current();
                if (TokenType::FlowEntry === $after->type) {
                    throw $this->trailingCommaError($comma);
                }
                if (TokenType::FlowMappingEnd === $after->type) {
                    $this->cursor++;
                    break;
                }
                continue;
            }
            if (TokenType::FlowMappingEnd === $this->current()->type) {
                $this->cursor++;
                break;
            }
            throw $this->unexpectedFlowTokenError($this->current());
        }

        return new MappingNode(
            position: $position,
            entries: $entries,
            tag: $resolvedTag,
            anchor: $resolvedAnchor,
        );
    }

    private function parseFlowItem(TokenType $endType): Node
    {
        if (TokenType::Scalar === $this->current()->type) {
            $keyToken = $this->current();
            if ($this->peek(1)?->type === TokenType::MappingValue) {
                $this->enterNode();
                try {
                    $keyNode = $this->scalarNode($keyToken);
                    $this->cursor += 2;
                    $value = $this->parseFlowMappingValue($this->previous()->position);
                } finally {
                    $this->leaveNode();
                }

                return new MappingNode(
                    position: $keyToken->position,
                    entries: [new MappingEntry(key: $keyNode, value: $value)],
                    tag: $this->consumePendingTag(),
                    anchor: $this->consumePendingAnchor(),
                );
            }
        }

        return $this->parseFlowNode();
    }

    private function parseFlowMappingValue(Position $valuePosition): Node
    {
        $token = $this->current();
        if (in_array($token->type, [TokenType::FlowEntry, TokenType::FlowMappingEnd], true)) {
            return $this->nullNode($valuePosition);
        }

        return $this->parseFlowNode();
    }

    private function parseFlowNode(): Node
    {
        $this->enterNode();
        try {
            $this->collectAttributes();
            $token = $this->current();

            if (TokenType::Scalar === $token->type) {
                $this->cursor++;

                return $this->scalarNode($token);
            }
            if (TokenType::Alias === $token->type) {
                $this->cursor++;

                return $this->aliasNode($token);
            }

            return match ($token->type) {
                TokenType::FlowSequenceStart => $this->parseFlowSequence(),
                TokenType::FlowMappingStart => $this->parseFlowMapping(),
                default => throw new SyntaxException(
                    message: 'Unexpected token in flow collection',
                    lineNumber: $token->position->line,
                    column: $token->position->column,
                ),
            };
        } finally {
            $this->leaveNode();
        }
    }

    private function scalarNode(Token $token): ScalarNode
    {
        return new ScalarNode(
            position: $token->position,
            style: $token->style ?? ScalarStyle::Plain,
            raw: $token->value,
            blockHeader: $token->blockHeader,
            tag: $this->consumePendingTag(),
            anchor: $this->consumePendingAnchor(),
        );
    }

    private function aliasNode(Token $token): AliasNode
    {
        $node = new AliasNode(position: $token->position, name: substr($token->value, 1));
        $this->pendingTag = null;
        $this->pendingAnchor = null;

        return $node;
    }

    private function nullNode(Position $position): ScalarNode
    {
        return new ScalarNode(
            position: $position,
            style: ScalarStyle::Plain,
            raw: '',
            tag: $this->consumePendingTag(),
            anchor: $this->consumePendingAnchor(),
        );
    }

    private function collectAttributes(): void
    {
        while ($this->cursor < $this->length) {
            $token = $this->current();
            if (TokenType::Tag === $token->type) {
                $this->pendingTag = $this->expandTag($token->value);
                $this->cursor++;
                continue;
            }
            if (TokenType::Anchor === $token->type) {
                $this->pendingAnchor = substr($token->value, 1);
                $this->cursor++;
                continue;
            }
            break;
        }
    }

    private function consumePendingTag(): ?string
    {
        $tag = $this->pendingTag;
        $this->pendingTag = null;

        return $tag;
    }

    private function consumePendingAnchor(): ?string
    {
        $anchor = $this->pendingAnchor;
        $this->pendingAnchor = null;

        return $anchor;
    }

    private function enterNode(): void
    {
        $this->depth++;
        if ($this->depth > $this->maxDepth) {
            throw new DepthLimitExceededException(
                message: 'Maximum node depth exceeded',
                lineNumber: $this->current()->position->line,
                column: $this->current()->position->column,
            );
        }
    }

    private function leaveNode(): void
    {
        $this->depth--;
    }

    private function compactCollectionError(Token $entryToken): UnsupportedFeatureException
    {
        return new UnsupportedFeatureException(
            message: 'Compact nested collections are not supported',
            lineNumber: $entryToken->position->line,
            column: $entryToken->position->column,
        );
    }

    private function trailingCommaError(Token $commaToken): SyntaxException
    {
        return new SyntaxException(
            message: 'Trailing comma in flow collection',
            lineNumber: $commaToken->position->line,
            column: $commaToken->position->column,
        );
    }

    private function unterminatedFlowError(): SyntaxException
    {
        $last = $this->tokens[$this->length - 1];

        return new SyntaxException(
            message: 'Unterminated flow collection',
            lineNumber: $last->position->line,
            column: $last->position->column + strlen($last->value),
        );
    }

    private function unexpectedFlowTokenError(Token $token): SyntaxException
    {
        return new SyntaxException(
            message: 'Expected flow entry separator or collection end',
            lineNumber: $token->position->line,
            column: $token->position->column,
        );
    }

    private function isMappingKey(Token $token): bool
    {
        $next = $this->peek(1);

        return null !== $next
            && TokenType::MappingValue === $next->type
            && $next->position->line === $token->position->line;
    }

    private function peek(int $offset): ?Token
    {
        $at = $this->cursor + $offset;

        return $at < $this->length ? $this->tokens[$at] : null;
    }

    private function current(): Token
    {
        return $this->tokens[$this->cursor];
    }

    private function previous(): Token
    {
        return $this->tokens[$this->cursor - 1];
    }

    private function zeroColumn(Token $token): int
    {
        return $token->position->column - 1;
    }

    private function lastPosition(): Position
    {
        return $this->tokens[$this->length - 1]->position;
    }
}
