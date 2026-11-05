<?php

declare(strict_types=1);

namespace Duyler\Yaml\Internal\Composer;

use Duyler\Yaml\Exception\AnchorNotFoundException;
use Duyler\Yaml\Exception\DepthLimitExceededException;
use Duyler\Yaml\Exception\DuplicateKeyException;
use Duyler\Yaml\Exception\InvalidKeyTypeException;
use Duyler\Yaml\Exception\InvalidTagValueException;
use Duyler\Yaml\Exception\MergeSourceException;
use Duyler\Yaml\Exception\SyntaxException;
use Duyler\Yaml\Contract\TagHandlerInterface;
use Duyler\Yaml\Internal\Node\AliasNode;
use Duyler\Yaml\Internal\Node\DocumentNode;
use Duyler\Yaml\Internal\Node\MappingNode;
use Duyler\Yaml\Internal\Node\Node;
use Duyler\Yaml\Internal\Node\ScalarNode;
use Duyler\Yaml\Internal\Node\SequenceNode;
use Duyler\Yaml\Internal\Scanner\Position;
use Duyler\Yaml\Internal\Scanner\ScalarStyle;
use Duyler\Yaml\Internal\Parser\ParseContext;
use Duyler\Yaml\TaggedValue;

use function array_key_exists;
use function array_pop;
use function is_array;
use function is_bool;
use function is_float;
use function sprintf;
use function str_starts_with;
use function in_array;

final class Composer
{
    private int $maxDepth = 256;
    private int $composeDepth = 0;
    private int $mergeDepth = 0;

    public function __construct(
        private readonly ScalarResolver $scalarResolver,
        private readonly AnchorRegistry $anchorRegistry,
        private readonly BuiltinTagResolver $builtinTagResolver = new BuiltinTagResolver(),
        /** @var array<string, TagHandlerInterface> */
        private readonly array $tagHandlers = [],
    ) {}

    public function compose(DocumentNode $document, ParseContext $context): mixed
    {
        $this->anchorRegistry->reset($context->limits->maxAliases);
        $this->maxDepth = $context->limits->maxDepth;
        $this->composeDepth = 0;
        $this->mergeDepth = 0;

        return $this->composeNode($document->root, $context->file);
    }

    private function composeNode(Node $node, ?string $file, bool $registerAnchor = true): mixed
    {
        $this->composeDepth++;
        if ($this->composeDepth > $this->maxDepth) {
            throw new DepthLimitExceededException(
                message: 'Maximum node depth exceeded',
                lineNumber: $node->position->line,
                column: $node->position->column,
            );
        }
        try {
            return $this->composeNodeInner($node, $file, $registerAnchor);
        } finally {
            $this->composeDepth--;
        }
    }

    private function composeNodeInner(Node $node, ?string $file, bool $registerAnchor): mixed
    {
        $anchor = $this->ownAnchor($node);
        $tag = $this->ownTag($node);
        if ($registerAnchor && null !== $anchor) {
            $this->anchorRegistry->register($anchor, $node);
        }
        if ($node instanceof AliasNode) {
            return $this->resolveAlias($node, $file);
        }
        if ($node instanceof SequenceNode) {
            $items = [];
            foreach ($node->items as $item) {
                /** @var string|bool|int|float|null|array<array-key, mixed>|TaggedValue $composedItem */
                $composedItem = $this->composeNode($item, $file);
                $items[] = $composedItem;
            }
            $value = $items;
        } elseif ($node instanceof MappingNode) {
            $value = $this->composeMapping($node, $file);
        } elseif ($node instanceof ScalarNode) {
            $value = $this->resolveScalar($node, $file);
        } else {
            throw new SyntaxException(
                message: 'Unknown node type',
                lineNumber: $node->position->line,
                column: $node->position->column,
            );
        }
        if (null !== $tag) {
            /** @var string|bool|int|float|null|array<array-key, mixed>|TaggedValue $value */
            $value = $this->applyTag($tag, $node, $value);
        }
        if (null !== $anchor) {
            $this->anchorRegistry->complete($anchor);
        }

        return $value;
    }

    private function ownTag(Node $node): ?string
    {
        if ($node instanceof ScalarNode || $node instanceof SequenceNode || $node instanceof MappingNode) {
            return $node->tag;
        }

        return null;
    }

    /**
     * @param string|bool|int|float|null|array<array-key, mixed> $value
     * @return string|bool|int|float|null|array<array-key, mixed>|TaggedValue|mixed
     */
    private function applyTag(string $tag, Node $node, string|bool|int|float|array|null $value): mixed
    {
        if (str_starts_with($tag, '!')) {
            return $this->applyLocalTag($tag, $value);
        }

        return $this->applyBuiltinTag($tag, $value, $node->position);
    }

    /**
     * @param string|bool|int|float|null|array<array-key, mixed> $value
     * @return string|bool|int|float|null|array<array-key, mixed>|TaggedValue|mixed
     */
    private function applyLocalTag(string $tag, string|bool|int|float|array|null $value): mixed
    {
        $handler = $this->tagHandlers[$tag] ?? null;
        if (null === $handler) {
            return new TaggedValue(tag: $tag, value: $value);
        }

        return $handler->resolve(new TaggedValue(tag: $tag, value: $value));
    }

    /**
     * @param string|bool|int|float|null|array<array-key, mixed> $value
     * @return string|bool|int|float|null|array<array-key, mixed>
     */
    private function applyBuiltinTag(string $tag, string|bool|int|float|array|null $value, Position $position): string|bool|int|float|array|null
    {
        if ('tag:yaml.org,2002:seq' === $tag || 'tag:yaml.org,2002:map' === $tag) {
            return $this->asCollection($tag, $value, $position);
        }
        if ('tag:yaml.org,2002:merge' === $tag) {
            throw new InvalidTagValueException(
                message: 'The "!!merge" tag is only valid on the "<<" key',
                lineNumber: $position->line,
                column: $position->column,
            );
        }
        if (is_array($value)) {
            if (in_array($tag, [
                'tag:yaml.org,2002:null',
                'tag:yaml.org,2002:bool',
                'tag:yaml.org,2002:int',
                'tag:yaml.org,2002:float',
                'tag:yaml.org,2002:str',
                'tag:yaml.org,2002:binary',
            ], true)) {
                throw new InvalidTagValueException(
                    message: sprintf('The "%s" tag requires a scalar value', $tag),
                    lineNumber: $position->line,
                    column: $position->column,
                );
            }

            return $this->builtinTagResolver->resolve($tag, null, $position);
        }

        return $this->builtinTagResolver->resolve($tag, $value, $position);
    }

    /**
     * @param string|bool|int|float|null|array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private function asCollection(string $tag, string|bool|int|float|array|null $value, Position $position): array
    {
        if (!is_array($value)) {
            throw new InvalidTagValueException(
                message: sprintf('The "%s" tag requires a collection value', $tag),
                lineNumber: $position->line,
                column: $position->column,
            );
        }

        return $value;
    }

    /** @return string|bool|int|float|null */
    private function resolveScalar(ScalarNode $node, ?string $file): string|bool|int|float|null
    {
        return $this->scalarResolver->resolve(
            $node->style,
            $node->raw,
            ScalarIndicators::fromNode($node),
            $node->position,
        );
    }

    private function composeMapping(MappingNode $node, ?string $file): array
    {
        $explicit = [];
        $mergeValueNodes = [];
        foreach ($node->entries as $entry) {
            if ($entry->key instanceof ScalarNode
                && 'tag:yaml.org,2002:merge' === $entry->key->tag
                && !$this->isMergeKey($entry->key)) {
                throw new SyntaxException(
                    message: 'The "!!merge" tag is only valid on the "<<" key',
                    lineNumber: $entry->key->position->line,
                    column: $entry->key->position->column,
                );
            }
            if ($this->isMergeKey($entry->key)) {
                if ([] !== $mergeValueNodes) {
                    throw new DuplicateKeyException(
                        message: 'Duplicate mapping key',
                        lineNumber: $entry->key->position->line,
                        column: $entry->key->position->column,
                    );
                }
                $mergeValueNodes[] = $entry->value;
                continue;
            }
            $key = $this->phpArrayKey($entry->key, $file);
            if (array_key_exists($key, $explicit)) {
                throw new DuplicateKeyException(
                    message: 'Duplicate mapping key',
                    lineNumber: $entry->key->position->line,
                    column: $entry->key->position->column,
                );
            }
            /** @var string|bool|int|float|null|array<array-key, mixed>|TaggedValue $entryValue */
            $entryValue = $this->composeNode($entry->value, $file);
            $explicit[$key] = $entryValue;
        }
        if ([] === $mergeValueNodes) {
            return $explicit;
        }

        return $this->mergeSourcesOver($explicit, $mergeValueNodes, $node->position, $file);
    }

    private function isMergeKey(Node $keyNode): bool
    {
        return $keyNode instanceof ScalarNode
            && ScalarStyle::Plain === $keyNode->style
            && '<<' === $keyNode->raw
            && (null === $keyNode->tag || 'tag:yaml.org,2002:merge' === $keyNode->tag);
    }

    /**
     * @param array<array-key, mixed> $explicit
     * @param list<Node> $mergeValueNodes
     * @return array<array-key, mixed>
     */
    private function mergeSourcesOver(array $explicit, array $mergeValueNodes, Position $position, ?string $file): array
    {
        $combined = [];
        foreach ($mergeValueNodes as $mergeValueNode) {
            /** @var string|bool|int|float|null|array<array-key, mixed>|TaggedValue $value */
            foreach ($this->mergeSourceEntries($mergeValueNode, $file) as $key => $value) {
                if (!array_key_exists($key, $combined)) {
                    $combined[$key] = $value;
                }
            }
        }
        /** @var string|bool|int|float|null|array<array-key, mixed>|TaggedValue $value */
        foreach ($explicit as $key => $value) {
            $combined[$key] = $value;
        }
        $this->assertResultDepthWithinLimits($combined, $position);

        return $combined;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function mergeSourceEntries(Node $source, ?string $file): array
    {
        $this->mergeDepth++;
        if ($this->mergeDepth > $this->maxDepth) {
            throw new DepthLimitExceededException(
                message: 'Maximum merge unfolding depth exceeded',
                lineNumber: $source->position->line,
                column: $source->position->column,
            );
        }
        try {
            return $this->mergeSourceEntriesInner($source, $file);
        } finally {
            $this->mergeDepth--;
        }
    }

    private function mergeSourceEntriesInner(Node $source, ?string $file): array
    {
        if ($source instanceof AliasNode) {
            $resolvedNode = $this->anchorRegistry->resolve($source->name, $source->position);
            if (!$resolvedNode instanceof MappingNode) {
                throw new MergeSourceException(
                    message: 'Merge source must be a mapping',
                    lineNumber: $source->position->line,
                    column: $source->position->column,
                );
            }

            return $this->composeMapping($resolvedNode, $file);
        }
        if ($source instanceof SequenceNode) {
            $combined = [];
            foreach ($source->items as $item) {
                if (!$item instanceof AliasNode) {
                    throw new MergeSourceException(
                        message: 'Merge source list must contain only aliases',
                        lineNumber: $item->position->line,
                        column: $item->position->column,
                    );
                }
                $resolvedNode = $this->anchorRegistry->resolve($item->name, $item->position);
                if (!$resolvedNode instanceof MappingNode) {
                    throw new MergeSourceException(
                        message: 'Merge source must be a mapping',
                        lineNumber: $item->position->line,
                        column: $item->position->column,
                    );
                }
                $composed = $this->composeMapping($resolvedNode, $file);
                /** @var string|bool|int|float|null|array<array-key, mixed>|TaggedValue $value */
                foreach ($composed as $key => $value) {
                    if (!array_key_exists($key, $combined)) {
                        $combined[$key] = $value;
                    }
                }
            }

            return $combined;
        }

        throw new MergeSourceException(
            message: 'Merge source must be an alias or a list of aliases',
            lineNumber: $source->position->line,
            column: $source->position->column,
        );
    }

    /**
     * @param array<array-key, mixed> $combined
     */
    private function assertResultDepthWithinLimits(array $combined, Position $position): void
    {
        $maxDepth = $this->maxDepth;
        /** @var list<array{0: array<array-key, mixed>, 1: int}> $stack */
        $stack = [[$combined, 1]];
        while ([] !== $stack) {
            [$value, $depth] = array_pop($stack);
            if ($depth > $maxDepth) {
                throw new DepthLimitExceededException(
                    message: 'Maximum node depth exceeded',
                    lineNumber: $position->line,
                    column: $position->column,
                );
            }
            /** @var string|bool|int|float|null|array<array-key, mixed> $child */
            foreach ($value as $child) {
                if (is_array($child)) {
                    $stack[] = [$child, $depth + 1];
                }
            }
        }
    }

    private function phpArrayKey(Node $keyNode, ?string $file): int|string
    {
        if ($keyNode instanceof AliasNode) {
            throw new InvalidKeyTypeException(
                message: 'Mapping key cannot be an alias',
                lineNumber: $keyNode->position->line,
                column: $keyNode->position->column,
            );
        }
        /** @var string|bool|int|float|null|array<array-key, mixed>|TaggedValue $key */
        $key = $this->composeNode($keyNode, $file);
        if ($key instanceof TaggedValue) {
            throw new InvalidKeyTypeException(
                message: 'Mapping key cannot be a tagged value',
                lineNumber: $keyNode->position->line,
                column: $keyNode->position->column,
            );
        }
        if (is_array($key)) {
            throw new SyntaxException(
                message: 'Mapping keys must be scalar values',
                lineNumber: $keyNode->position->line,
                column: $keyNode->position->column,
            );
        }
        if (is_bool($key)) {
            return (int) $key;
        }
        if (null === $key) {
            return '';
        }
        if (is_float($key)) {
            return (int) $key;
        }

        return $key;
    }

    private function resolveAlias(AliasNode $node, ?string $file): mixed
    {
        if (!$this->anchorRegistry->has($node->name)) {
            throw new AnchorNotFoundException(
                message: 'Unknown anchor "' . $node->name . '"',
                lineNumber: $node->position->line,
                column: $node->position->column,
            );
        }

        $resolved = $this->anchorRegistry->resolve($node->name, $node->position);

        return $this->composeNode($resolved, $file, registerAnchor: false);
    }

    private function ownAnchor(Node $node): ?string
    {
        if ($node instanceof ScalarNode || $node instanceof SequenceNode || $node instanceof MappingNode) {
            return $node->anchor;
        }

        return null;
    }
}
