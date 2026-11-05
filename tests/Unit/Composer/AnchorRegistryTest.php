<?php

declare(strict_types=1);

namespace Duyler\Yaml\Test\Unit\Composer;

use Duyler\Yaml\Exception\AliasCycleException;
use Duyler\Yaml\Exception\AliasLimitExceededException;
use Duyler\Yaml\Exception\AnchorNotFoundException;
use Duyler\Yaml\Exception\DuplicateAnchorException;
use Duyler\Yaml\Internal\Composer\AnchorEntry;
use Duyler\Yaml\Internal\Composer\AnchorRegistry;
use Duyler\Yaml\Internal\Composer\AnchorStatus;
use Duyler\Yaml\Internal\Node\ScalarNode;
use Duyler\Yaml\Internal\Scanner\Position;
use Duyler\Yaml\Internal\Scanner\ScalarStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class AnchorRegistryTest extends TestCase
{
    private readonly AnchorRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new AnchorRegistry();
    }

    #[Test]
    public function registered_anchor_resolves_to_the_same_node(): void
    {
        $node = self::scalar('x', 1, 1);
        $this->registry->register('a', $node);
        $this->registry->complete('a');

        self::assertTrue($this->registry->has('a'));
        self::assertSame($node, $this->registry->resolve('a'));
    }

    #[Test]
    public function complete_of_unknown_anchor_is_ignored(): void
    {
        $this->registry->complete('missing');

        self::assertFalse($this->registry->has('missing'));
    }

    #[Test]
    public function registered_anchor_is_building_until_completed(): void
    {
        $this->registry->register('a', self::scalar('x', 1, 1));

        $this->expectException(AliasCycleException::class);

        try {
            $this->registry->resolve('a');
            self::fail('AliasCycleException expected');
        } catch (AliasCycleException $exception) {
            self::assertSame('0:0: Alias cycle detected on anchor "a"', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    public function completed_anchor_resolves_and_counts_deployment(): void
    {
        $this->registry->register('a', self::scalar('x', 1, 1));
        $this->registry->complete('a');
        $this->registry->resolve('a', new Position(2, 3));

        self::assertSame(1, $this->registry->deployments());
    }

    #[Test]
    public function unknown_name_is_not_registered(): void
    {
        self::assertFalse($this->registry->has('missing'));
    }

    #[Test]
    public function duplicate_anchor_throws_with_second_node_coordinates(): void
    {
        $this->registry->register('a', self::scalar('x', 1, 1));

        $this->expectException(DuplicateAnchorException::class);

        try {
            $this->registry->register('a', self::scalar('y', 2, 5));
            self::fail('DuplicateAnchorException expected');
        } catch (DuplicateAnchorException $exception) {
            self::assertSame('2:5: Duplicate anchor "a"', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    public function unknown_anchor_resolution_throws(): void
    {
        $this->expectException(AnchorNotFoundException::class);

        try {
            $this->registry->resolve('nope');
            self::fail('AnchorNotFoundException expected');
        } catch (AnchorNotFoundException $exception) {
            self::assertSame('0:0: Unknown anchor "nope"', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    public function distinct_names_resolve_independently(): void
    {
        $first = self::scalar('x', 1, 1);
        $second = self::scalar('y', 2, 1);
        $this->registry->register('a', $first);
        $this->registry->register('b', $second);
        $this->registry->complete('a');
        $this->registry->complete('b');

        self::assertSame($first, $this->registry->resolve('a'));
        self::assertSame($second, $this->registry->resolve('b'));
    }

    #[Test]
    public function reset_clears_entries_and_deployments(): void
    {
        $this->registry->register('a', self::scalar('x', 1, 1));
        $this->registry->complete('a');
        $this->registry->resolve('a');
        $this->registry->reset(256);

        self::assertFalse($this->registry->has('a'));
        self::assertSame(0, $this->registry->deployments());
    }

    #[Test]
    public function reset_applies_new_alias_limit(): void
    {
        $this->registry->reset(1);
        $this->registry->register('a', self::scalar('x', 1, 1));
        $this->registry->complete('a');

        $this->registry->resolve('a', new Position(1, 1));

        $this->expectException(AliasLimitExceededException::class);

        $this->registry->resolve('a', new Position(1, 1));
    }

    #[Test]
    public function alias_limit_is_enforced_per_registry(): void
    {
        $registry = new AnchorRegistry(maxAliases: 2);
        $registry->register('a', self::scalar('x', 1, 1));
        $registry->complete('a');

        $registry->resolve('a', new Position(1, 1));
        $registry->resolve('a', new Position(1, 1));

        $this->expectException(AliasLimitExceededException::class);

        try {
            $registry->resolve('a', new Position(1, 1));
            self::fail('AliasLimitExceededException expected');
        } catch (AliasLimitExceededException $exception) {
            self::assertSame(3, $registry->deployments());

            throw $exception;
        }
    }

    #[Test]
    public function anchor_status_transitions_from_building_to_complete(): void
    {
        $this->registry->register('a', self::scalar('x', 1, 1));
        $this->registry->complete('a');
        $this->registry->complete('a');

        $entry = $this->entryFor('a');

        self::assertSame(AnchorStatus::Complete, $entry->status);
        self::assertSame('x', $entry->node->raw);
    }

    private function entryFor(string $name): AnchorEntry
    {
        $mirror = new ReflectionProperty(AnchorRegistry::class, 'entries');
        $entries = $mirror->getValue($this->registry);
        if (!isset($entries[$name])) {
            self::fail('Anchor entry not found');
        }

        return $entries[$name];
    }

    private static function scalar(string $raw, int $line, int $column): ScalarNode
    {
        return new ScalarNode(
            position: new Position(line: $line, column: $column),
            style: ScalarStyle::Plain,
            raw: $raw,
        );
    }
}
