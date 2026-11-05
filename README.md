# Yaml

YAML 1.2.2 parser for PHP with strict typing. Independent implementation of the YAML 1.2.2 specification: block and flow collections, multi-document files, anchors, aliases, merge keys, and tags. Errors are reported as typed exceptions carrying the file, line, and column of the failure; silent null results are never returned for invalid input.

## Requirements

- PHP 8.4 or newer

## Installation

```bash
composer require duyler/yaml
```

## Usage

Parse a single document:

```php
use Duyler\Yaml\Yaml;

$value = Yaml::parse($input);
```

Parse every document of a multi-document stream:

```php
$values = Yaml::parseAll($input);
```

Parse from a file:

```php
$value = Yaml::parseFile('/path/to/config.yaml');
$values = Yaml::parseAllFile('/path/to/config.yaml');
```

Tune the parser with a typed configuration object:

```php
use Duyler\Yaml\ParseConfig;

$value = Yaml::parse($input, new ParseConfig(maxDepth: 64, maxAliases: 128));
```

Multi-document streams and `%YAML`/`%TAG` directives are supported. `%YAML` accepts version 1.2 only; directives are validated per document.

Built-in tags `!!null`, `!!bool`, `!!int`, `!!float`, `!!str`, `!!binary`, `!!seq`, `!!map`, and `!!merge` are resolved out of the box. A local tag resolves to a `TaggedValue` object holding the tag name and the parsed value:

```php
use Duyler\Yaml\TaggedValue;

$value = Yaml::parse('!custom 42');
// TaggedValue { tag: '!custom', value: 42 }
```

To turn local tags into anything you need, register a handler per tag:

```php
use Duyler\Yaml\Contract\TagHandlerInterface;
use Duyler\Yaml\ParseConfig;
use Duyler\Yaml\TaggedValue;

$handler = new class() implements TagHandlerInterface {
    public function resolve(TaggedValue $value): mixed
    {
        return $value->value * 2;
    }
};

$value = Yaml::parse('!double 21', new ParseConfig(tagHandlers: ['!double' => $handler]));
// 42
```

## Limits

Protection against billion-laughs style attacks and unbounded recursion is built in.

| Option | Default | Description |
| --- | --- | --- |
| maxDepth | 256 | Maximum nesting depth of collections |
| maxAliases | 512 | Maximum number of resolved aliases per document |

## Differences from Symfony Yaml

This package follows the YAML 1.2.2 core schema, where Symfony Yaml keeps YAML 1.1 compatibility in scalar resolution:

| Input | This package | Symfony Yaml |
| --- | --- | --- |
| `yes` | `'yes'` (string) | `true` (boolean) |
| `010` | `'010'` (string) | `8` (octal integer) |
| `1_000` | `'1_000'` (string) | `1000` (integer) |

In the YAML 1.2.2 core schema booleans are only `true` and `false`, octal integers require the `0o` prefix, and underscores are not allowed inside numeric literals, so all three inputs above are plain scalars and resolve to strings.

Unlike Symfony Yaml this package is parse-only and has no document-type flags: input is always untrusted data, `parse` expects exactly one document and throws on multi-document streams (use `parseAll`), and `parseFile` reads only local regular files, rejecting stream wrappers up front.

## Notes for local debugging

Functional tests build nesting depth of 257 levels and alias cascades. With xdebug enabled locally set `xdebug.max_nesting_level` to at least 2048; CI images without xdebug are not affected.
