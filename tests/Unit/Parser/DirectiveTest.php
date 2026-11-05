<?php

declare(strict_types=1);

namespace Duyler\Yaml\Test\Unit\Parser;

use Duyler\Yaml\Exception\DirectiveException;
use Duyler\Yaml\Exception\ParseException;
use Duyler\Yaml\Exception\VersionException;
use Duyler\Yaml\Internal\Node\AliasNode;
use Duyler\Yaml\Internal\Node\DocumentNode;
use Duyler\Yaml\Internal\Node\MappingNode;
use Duyler\Yaml\Internal\Node\Node;
use Duyler\Yaml\Internal\Node\ScalarNode;
use Duyler\Yaml\Internal\Node\SequenceNode;
use Duyler\Yaml\Internal\Parser\Limits;
use Duyler\Yaml\Internal\Parser\ParseContext;
use Duyler\Yaml\Internal\Parser\Parser;
use Duyler\Yaml\Internal\Scanner\Scanner;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;

class DirectiveTest extends TestCase
{
    #[Test]
    #[DataProvider('directive_documents')]
    public function directives_are_validated_and_recorded(string $input, array $expected): void
    {
        $documents = $this->parse($input);

        self::assertSame($expected, self::dumpDocuments($documents));
    }

    public static function directive_documents(): array
    {
        return [
            'yaml_directive_version_is_recorded' => [
                "%YAML 1.2\n---\na: 1",
                [['doc', 2, 1, '1.2', [], ['map', 3, 1, null, null,
                    ['scalar', 'a', 'Plain', 3, 1, null, null, null, null],
                    ['scalar', '1', 'Plain', 3, 4, null, null, null, null]]]],
            ],
            'yaml_directive_accepts_revision' => [
                "%YAML 1.2.9\n---\nplain",
                [['doc', 2, 1, '1.2.9', [], ['scalar', 'plain', 'Plain', 3, 1, null, null, null, null]]],
            ],
            'whitespace_and_comment_in_directive_are_ignored' => [
                "%YAML  1.2 # note\n---\na: 1",
                [['doc', 2, 1, '1.2', [], ['map', 3, 1, null, null,
                    ['scalar', 'a', 'Plain', 3, 1, null, null, null, null],
                    ['scalar', '1', 'Plain', 3, 4, null, null, null, null]]]],
            ],
            'tag_directive_expands_scalar_tag' => [
                "%TAG !e! tag:example.com,2000:app/\n---\n!e!x val",
                [['doc', 2, 1, null, ['!e!' => 'tag:example.com,2000:app/'],
                    ['scalar', 'val', 'Plain', 3, 6, 'tag:example.com,2000:app/x', null, null, null]]],
            ],
            'tag_directive_expands_sequence_tag' => [
                "%TAG !e! tag:example.com,2000:app/\n---\n!e!seq\n- 1",
                [['doc', 2, 1, null, ['!e!' => 'tag:example.com,2000:app/'],
                    ['seq', 4, 1, 'tag:example.com,2000:app/seq', null,
                        ['scalar', '1', 'Plain', 4, 3, null, null, null, null]]]],
            ],
            'tag_directive_expands_mapping_key_tag' => [
                "%TAG !e! tag:example.com,2000:app/\n---\n!e!key\na: 1",
                [['doc', 2, 1, null, ['!e!' => 'tag:example.com,2000:app/'],
                    ['map', 4, 1, null, null,
                        ['scalar', 'a', 'Plain', 4, 1, 'tag:example.com,2000:app/key', null, null, null],
                        ['scalar', '1', 'Plain', 4, 4, null, null, null, null]]]],
            ],
            'tag_directive_expands_mapping_value_tag' => [
                "%TAG !e! tag:example.com,2000:app/\n---\nk: !e!x v",
                [['doc', 2, 1, null, ['!e!' => 'tag:example.com,2000:app/'],
                    ['map', 3, 1, null, null,
                        ['scalar', 'k', 'Plain', 3, 1, null, null, null, null],
                        ['scalar', 'v', 'Plain', 3, 9, 'tag:example.com,2000:app/x', null, null, null]]]],
            ],
            'tag_directive_expands_flow_item_tag' => [
                "%TAG !e! tag:example.com,2000:app/\n---\n[!e!x v]",
                [['doc', 2, 1, null, ['!e!' => 'tag:example.com,2000:app/'],
                    ['seq', 3, 1, null, null,
                        ['scalar', 'v', 'Plain', 3, 7, 'tag:example.com,2000:app/x', null, null, null]]]],
            ],
            'double_bang_tag_expands_to_builtin' => [
                '!!str x',
                [['doc', 1, 1, null, [], ['scalar', 'x', 'Plain', 1, 7, 'tag:yaml.org,2002:str', null, null, null]]],
            ],
            'verbatim_tag_is_not_expanded' => [
                '!<tag:example.com,2000:x> v',
                [['doc', 1, 1, null, [], ['scalar', 'v', 'Plain', 1, 27, '!<tag:example.com,2000:x>', null, null, null]]],
            ],
            'primary_handle_registration_expands_local_tags' => [
                "%TAG ! myprefix/\n---\n!x v",
                [['doc', 2, 1, null, ['!' => 'myprefix/'],
                    ['scalar', 'v', 'Plain', 3, 4, 'myprefix/x', null, null, null]]],
            ],
            'registry_resets_between_documents' => [
                "%TAG !e! tag:example.com,2000:app/\n---\n!e!a v\n---\n!e!b w",
                [
                    ['doc', 2, 1, null, ['!e!' => 'tag:example.com,2000:app/'],
                        ['scalar', 'v', 'Plain', 3, 6, 'tag:example.com,2000:app/a', null, null, null]],
                    ['doc', 4, 1, null, [],
                        ['scalar', 'w', 'Plain', 5, 6, '!e!b', null, null, null]],
                ],
            ],
            'same_handle_in_adjacent_documents_is_valid' => [
                "%TAG !e! tag:example.com,2000:app/\n---\na\n...\n%TAG !e! tag:other.example.com,2000:app/\n---\n!e!x v",
                [
                    ['doc', 2, 1, null, ['!e!' => 'tag:example.com,2000:app/'],
                        ['scalar', 'a', 'Plain', 3, 1, null, null, null, null]],
                    ['doc', 6, 1, null, ['!e!' => 'tag:other.example.com,2000:app/'],
                        ['scalar', 'v', 'Plain', 7, 6, 'tag:other.example.com,2000:app/x', null, null, null]],
                ],
            ],
            'version_resets_between_documents' => [
                "%YAML 1.2\n---\na\n...\n%YAML 1.2.1\n---\nb",
                [
                    ['doc', 2, 1, '1.2', [], ['scalar', 'a', 'Plain', 3, 1, null, null, null, null]],
                    ['doc', 6, 1, '1.2.1', [], ['scalar', 'b', 'Plain', 7, 1, null, null, null, null]],
                ],
            ],
            'documents_after_end_marker_form_list' => [
                "---\na: 1\n...\n---\nb: 2\n...",
                [
                    ['doc', 1, 1, null, [], ['map', 2, 1, null, null,
                        ['scalar', 'a', 'Plain', 2, 1, null, null, null, null],
                        ['scalar', '1', 'Plain', 2, 4, null, null, null, null]]],
                    ['doc', 4, 1, null, [], ['map', 5, 1, null, null,
                        ['scalar', 'b', 'Plain', 5, 1, null, null, null, null],
                        ['scalar', '2', 'Plain', 5, 4, null, null, null, null]]],
                ],
            ],
            'empty_document_after_directives' => [
                "%YAML 1.2\n---\n",
                [['doc', 2, 1, '1.2', [], ['scalar', '', 'Plain', 2, 1, null, null, null, null]]],
            ],
        ];
    }

    #[Test]
    #[DataProvider('directive_errors')]
    public function invalid_directive_throws_typed_exception_with_coordinates(
        string $input,
        string $exceptionClass,
        string $message,
        int $line,
        int $column,
    ): void {
        $this->expectException($exceptionClass);

        try {
            $this->parse($input);
            self::fail($exceptionClass . ' expected');
        } catch (ParseException $exception) {
            self::assertSame($line . ':' . $column . ': ' . $message, $exception->getMessage());
            self::assertSame($line, $exception->lineNumber);
            self::assertSame($column, $exception->column);

            throw $exception;
        }
    }

    public static function directive_errors(): array
    {
        return [
            'yaml_version_1_1_rejected' => [
                "%YAML 1.1\n---\na: 1",
                VersionException::class,
                'Unsupported YAML version "1.1"',
                1,
                1,
            ],
            'yaml_minor_1_3_rejected' => [
                "%YAML 1.3\n---\na",
                VersionException::class,
                'Unsupported YAML version "1.3"',
                1,
                1,
            ],
            'yaml_major_2_rejected' => [
                "%YAML 2.0\n---\na",
                VersionException::class,
                'Unsupported YAML version "2.0"',
                1,
                1,
            ],
            'yaml_malformed_version_rejected' => [
                "%YAML x\n---\na",
                VersionException::class,
                'Invalid %YAML directive',
                1,
                1,
            ],
            'yaml_missing_version_rejected' => [
                "%YAML\n---\na",
                VersionException::class,
                'Invalid %YAML directive',
                1,
                1,
            ],
            'duplicate_yaml_directive_rejected' => [
                "%YAML 1.2\n%YAML 1.2\n---\na",
                DirectiveException::class,
                'Duplicate %YAML directive',
                2,
                1,
            ],
            'duplicate_tag_handle_rejected' => [
                "%TAG !e! tag:example.com,2000:app/\n%TAG !e! tag:other\n---\na",
                DirectiveException::class,
                'Duplicate tag handle "!e!"',
                2,
                1,
            ],
            'unknown_directive_rejected' => [
                "%FOO x\n---\na",
                DirectiveException::class,
                'Unknown directive "%FOO"',
                1,
                1,
            ],
            'bare_percent_rejected' => [
                "%\n---\na",
                DirectiveException::class,
                'Unknown directive "%"',
                1,
                1,
            ],
            'directives_require_document_start_before_content' => [
                "%YAML 1.2\na: 1",
                DirectiveException::class,
                'Directives must be followed by a document start marker',
                1,
                1,
            ],
            'directives_require_document_start_before_eof' => [
                '%YAML 1.2',
                DirectiveException::class,
                'Directives must be followed by a document start marker',
                1,
                1,
            ],
            'directives_after_end_marker_require_document_start' => [
                "a: 1\n...\n%YAML 1.2\n",
                DirectiveException::class,
                'Directives must be followed by a document start marker',
                3,
                1,
            ],
            'directive_after_content_rejected' => [
                "a: 1\n%YAML 1.2",
                DirectiveException::class,
                'Directive must not appear after document content',
                2,
                1,
            ],
            'directive_after_content_before_document_start_rejected' => [
                "a: 1\n%TAG !e! p\n---\nb: 2",
                DirectiveException::class,
                'Directive must not appear after document content',
                2,
                1,
            ],
            'directive_after_document_start_rejected' => [
                "---\n%YAML 1.2",
                DirectiveException::class,
                'Directive must precede the document start marker',
                2,
                1,
            ],
            'tag_directive_missing_prefix_rejected' => [
                "%TAG !e!\n---\na",
                DirectiveException::class,
                'Invalid %TAG directive',
                1,
                1,
            ],
            'tag_directive_without_handle_rejected' => [
                "%TAG\n---\na",
                DirectiveException::class,
                'Invalid %TAG directive',
                1,
                1,
            ],
            'tag_directive_handle_without_leading_bang_rejected' => [
                "%TAG e! p\n---\na",
                DirectiveException::class,
                'Invalid %TAG directive',
                1,
                1,
            ],
            'tag_directive_handle_without_trailing_bang_rejected' => [
                "%TAG !e p\n---\na",
                DirectiveException::class,
                'Invalid %TAG directive',
                1,
                1,
            ],
        ];
    }

    #[Test]
    public function version_exception_exposes_parse_coordinates(): void
    {
        $exception = new VersionException(
            message: 'Unsupported YAML version "1.1"',
            lineNumber: 2,
            column: 3,
        );

        self::assertInstanceOf(ParseException::class, $exception);
        self::assertSame('2:3: Unsupported YAML version "1.1"', $exception->getMessage());
        self::assertSame(2, $exception->lineNumber);
        self::assertSame(3, $exception->column);
    }

    #[Test]
    public function directive_exception_exposes_parse_coordinates(): void
    {
        $exception = new DirectiveException(
            message: 'Duplicate tag handle "!e!"',
            lineNumber: 4,
            column: 5,
        );

        self::assertInstanceOf(ParseException::class, $exception);
        self::assertSame('4:5: Duplicate tag handle "!e!"', $exception->getMessage());
        self::assertSame(4, $exception->lineNumber);
        self::assertSame(5, $exception->column);
    }

    private function parse(string $input): array
    {
        $tokens = new Scanner()->scan($input);

        return new Parser()->parse($tokens, $this->context());
    }

    private function context(): ParseContext
    {
        return new ParseContext(file: null, limits: new Limits());
    }

    private static function dumpDocuments(array $documents): array
    {
        return array_map(static fn(DocumentNode $document): array => [
            'doc',
            $document->position->line,
            $document->position->column,
            $document->version,
            $document->tagPrefixes,
            self::dumpNode($document->root),
        ], $documents);
    }

    private static function dumpNode(Node $node): array
    {
        if ($node instanceof ScalarNode) {
            return [
                'scalar',
                $node->raw,
                $node->style->name,
                $node->position->line,
                $node->position->column,
                $node->tag,
                $node->anchor,
                $node->blockHeader?->chomping->name,
                $node->blockHeader?->explicitIndent,
            ];
        }
        if ($node instanceof SequenceNode) {
            return [
                'seq',
                $node->position->line,
                $node->position->column,
                $node->tag,
                $node->anchor,
                ...array_map(static fn(Node $item): array => self::dumpNode($item), $node->items),
            ];
        }
        if ($node instanceof MappingNode) {
            $dump = ['map', $node->position->line, $node->position->column, $node->tag, $node->anchor];
            foreach ($node->entries as $entry) {
                $dump[] = self::dumpNode($entry->key);
                $dump[] = self::dumpNode($entry->value);
            }

            return $dump;
        }
        if ($node instanceof AliasNode) {
            return ['alias', $node->name, $node->position->line, $node->position->column];
        }

        throw new LogicException('Unknown node type');
    }
}
