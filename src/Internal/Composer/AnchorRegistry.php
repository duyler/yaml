<?php

declare(strict_types=1);

namespace Duyler\Yaml\Internal\Composer;

use Duyler\Yaml\Exception\AliasCycleException;
use Duyler\Yaml\Exception\AliasLimitExceededException;
use Duyler\Yaml\Exception\AnchorNotFoundException;
use Duyler\Yaml\Exception\DuplicateAnchorException;
use Duyler\Yaml\Internal\Node\Node;
use Duyler\Yaml\Internal\Scanner\Position;

use function array_key_exists;
use function sprintf;

final class AnchorRegistry
{
    /** @var array<string, AnchorEntry> */
    private array $entries = [];
    private int $deployments = 0;

    public function __construct(
        private int $maxAliases = 512,
    ) {}

    public function reset(int $maxAliases = 512): void
    {
        $this->entries = [];
        $this->deployments = 0;
        $this->maxAliases = $maxAliases;
    }

    public function deployments(): int
    {
        return $this->deployments;
    }

    public function register(string $name, Node $node): void
    {
        if (array_key_exists($name, $this->entries)) {
            throw new DuplicateAnchorException(
                message: sprintf('Duplicate anchor "%s"', $name),
                lineNumber: $node->position->line,
                column: $node->position->column,
            );
        }
        $this->entries[$name] = new AnchorEntry(AnchorStatus::Building, $node);
    }

    public function complete(string $name): void
    {
        if (!array_key_exists($name, $this->entries)) {
            return;
        }
        $entry = $this->entries[$name];
        $this->entries[$name] = new AnchorEntry(AnchorStatus::Complete, $entry->node);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->entries);
    }

    public function resolve(string $name, ?Position $at = null): Node
    {
        if (!array_key_exists($name, $this->entries)) {
            throw new AnchorNotFoundException(
                message: sprintf('Unknown anchor "%s"', $name),
                lineNumber: 0,
                column: 0,
            );
        }
        $entry = $this->entries[$name];

        if (AnchorStatus::Building === $entry->status) {
            throw new AliasCycleException(
                message: sprintf('Alias cycle detected on anchor "%s"', $name),
                lineNumber: $at?->line ?? 0,
                column: $at?->column ?? 0,
            );
        }

        $this->deployments++;
        if ($this->deployments > $this->maxAliases) {
            throw new AliasLimitExceededException(
                message: sprintf('Alias deployments exceed the limit of %d', $this->maxAliases),
                lineNumber: $at?->line ?? 0,
                column: $at?->column ?? 0,
            );
        }

        return $entry->node;
    }
}
