<?php

declare(strict_types=1);

namespace Duyler\Yaml;

use Duyler\Yaml\Exception\FileNotFoundException;
use Duyler\Yaml\Exception\FileNotReadableException;
use Duyler\Yaml\Exception\MultipleDocumentsException;
use Duyler\Yaml\Exception\ParseException;
use Duyler\Yaml\Internal\Composer\AnchorRegistry;
use Duyler\Yaml\Internal\Composer\BuiltinTagResolver;
use Duyler\Yaml\Internal\Composer\Composer;
use Duyler\Yaml\Internal\Composer\ScalarResolver;
use Duyler\Yaml\Internal\InputValidator;
use Duyler\Yaml\Internal\Node\DocumentNode;
use Duyler\Yaml\Internal\Parser\Limits;
use Duyler\Yaml\Internal\Parser\ParseContext;
use Duyler\Yaml\Internal\Parser\Parser;
use Duyler\Yaml\Internal\Scanner\Scanner;

use function count;
use function file_get_contents;
use function is_file;
use function is_readable;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * @psalm-type ParsedValue = null|bool|int|float|string|array<array-key, mixed>|TaggedValue
 */
final class Yaml
{
    /** @return null|bool|int|float|string|array<array-key, mixed>|TaggedValue */
    public static function parse(string $input, ?ParseConfig $config = null): mixed
    {
        $config ??= new ParseConfig();
        $context = self::bootContext($config, null);
        $documents = self::runDocuments($input, $context);
        $document = self::assertSingleDocument($documents, $context);

        return self::composeDocument($document, $context, $config);
    }

    /** @return list<null|bool|int|float|string|array<array-key, mixed>|TaggedValue> */
    public static function parseAll(string $input, ?ParseConfig $config = null): array
    {
        $config ??= new ParseConfig();
        $context = self::bootContext($config, null);
        $values = [];
        foreach (self::runDocuments($input, $context) as $document) {
            $values[] = self::composeDocument($document, $context, $config);
        }

        return $values;
    }

    public static function parseFile(string $filename, ?ParseConfig $config = null): mixed
    {
        $content = self::readFile($filename);
        $config ??= new ParseConfig();
        $context = self::bootContext($config, $filename);
        $documents = self::runDocuments($content, $context);
        $document = self::assertSingleDocument($documents, $context);

        return self::composeDocument($document, $context, $config);
    }

    /** @return list<mixed> */
    public static function parseAllFile(string $filename, ?ParseConfig $config = null): array
    {
        $content = self::readFile($filename);
        $config ??= new ParseConfig();
        $context = self::bootContext($config, $filename);
        $values = [];
        foreach (self::runDocuments($content, $context) as $document) {
            $values[] = self::composeDocument($document, $context, $config);
        }

        return $values;
    }

    private static function readFile(string $filename): string
    {
        if (false === is_file($filename)) {
            throw new FileNotFoundException($filename);
        }

        if (false === is_readable($filename)) {
            throw new FileNotReadableException($filename);
        }

        $content = file_get_contents($filename);

        if (false === $content) {
            throw new FileNotReadableException($filename);
        }

        return $content;
    }

    private static function bootContext(ParseConfig $config, ?string $file): ParseContext
    {
        return new ParseContext(
            file: $file,
            limits: new Limits(maxDepth: $config->maxDepth, maxAliases: $config->maxAliases),
        );
    }

    /** @return list<DocumentNode> */
    private static function runDocuments(string $input, ParseContext $context): array
    {
        try {
            $input = new InputValidator()->validate($input);
            $tokens = new Scanner()->scan($input);
            /** @var list<DocumentNode> $documents */
            $documents = new Parser()->parse($tokens, $context);

            return $documents;
        } catch (ParseException $exception) {
            throw self::withFileName($exception, $context->file);
        }
    }

    /**
     * @param list<DocumentNode> $documents
     * @return DocumentNode|null
     */
    private static function assertSingleDocument(array $documents, ParseContext $context): ?DocumentNode
    {
        if (1 < count($documents)) {
            throw new MultipleDocumentsException(
                message: 'The input contains multiple documents, use parseAll() to parse them all',
                lineNumber: $documents[1]->position->line,
                column: $documents[1]->position->column,
                fileName: $context->file,
            );
        }

        return $documents[0] ?? null;
    }

    /**
     * @param DocumentNode|null $document
     * @return null|bool|int|float|string|array<array-key, mixed>|TaggedValue
     */
    private static function composeDocument(?DocumentNode $document, ParseContext $context, ParseConfig $config): mixed
    {
        if (null === $document) {
            return null;
        }
        try {
            $composer = new Composer(
                new ScalarResolver(),
                new AnchorRegistry(),
                new BuiltinTagResolver(),
                $config->tagHandlers,
            );
            /** @var null|bool|int|float|string|array<array-key, mixed>|TaggedValue $value */
            $value = $composer->compose($document, $context);
        } catch (ParseException $exception) {
            throw self::withFileName($exception, $context->file);
        }

        return $value;
    }

    private static function withFileName(ParseException $exception, ?string $file): ParseException
    {
        if (null === $file || null !== $exception->fileName) {
            return $exception;
        }
        $prefix = $exception->lineNumber . ':' . $exception->column . ': ';
        $message = $exception->getMessage();
        if (str_starts_with($message, $prefix)) {
            $message = substr($message, strlen($prefix));
        }
        $class = $exception::class;

        return new $class($message, $exception->lineNumber, $exception->column, $file);
    }
}
