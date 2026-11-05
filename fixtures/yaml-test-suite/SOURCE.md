# yaml-test-suite fixtures

Vendored subset of the official yaml-test-suite, run as regular PHPUnit fixtures
by `tests/Functional/YamlTestSuite/YamlTestSuiteTest.php`.

## Source

- Repository: https://github.com/yaml/yaml-test-suite
- Commit: da267a5c4782e7361e82889e76c0dc7df0e1e870 (main, 2025-12-25)
- Vendored on: 2026-11-05
- Files: `src/*.yaml` only (351 files in the snapshot); the frozen `data` tags from 2022 are not used.

## License

yaml-test-suite is distributed under the MIT license.

Copyright (c) 2016-2020 Ingy döt Net

## Test file format (snapshot da267a5)

- First line is the `---` document marker; every test is a list item starting
  with `- ` in column 0.
- Keys are indented by 2 spaces: inline scalars `name`, `from`, `tags`,
  `fail: true` (marks an error test; there is no `error:` section in this
  snapshot), and literal blocks with content indented by 4 spaces: `yaml`
  (input), `tree`, `json`, `dump`, `emit`.
- Files may contain subtests (`- yaml:` without a `name`), for example `VJP3`;
  such entries are named `{file}#{index}`.
- Rare keys `note`, `also`, `toke` are ignored by the runner.

## Oracle

Positive tests: `assertSame(Yaml::parseAll(decode(dump)), Yaml::parseAll(decode(yaml)))`
(mapping key order is significant; `TaggedValue` objects are normalized to
`{tag, value}` arrays before comparison). Error tests (`fail: true`) are
expected to throw `Duyler\Yaml\Exception\ParseException` or a subclass.
The `json` section is not used as an oracle (JSON object keys are always
strings, which conflicts with int/bool/null key resolution).

## Visual markers (decoded before parsing)

| Marker | Decoded to |
| --- | --- |
| `␣` | trailing space |
| `»`, `—»`, `——»`, `———»` | tab |
| `∎` | no final line feed |
| `⇔` | BOM (not present in this snapshot) |
| `←` | CR (not present in this snapshot) |
| `↵` | trailing line feed marker (not decoded; such tests are excluded) |

## Filtering rules (whitelist)

1. The `yaml` block is mandatory; positive tests require the `dump` block
   (tests with `json` only are not included).
2. Input and `dump` must stay inside the supported subset of this epic:
   scalars of the five styles, block seq/map, flow collections, comments,
   anchors/aliases, merge keys, tags `!!null/bool/int/float/str/binary/seq/map`
   plus local tags, multi-document, `%YAML`/`%TAG` directives.
3. Tests with non-scalar keys (`? ` explicit key form in `yaml` or `dump`) are
   excluded.
4. Tests with multi-line quoted scalars, multi-line plain scalars, flow edge
   cases (implicit entries, adjacent colon), compact block collections, and
   tabs inside plain scalar content are excluded.
5. Error tests (`fail: true`) are included only when the parser is expected to
   reject the input per the supported semantics (duplicate keys, tabs in
   indentation, invalid UTF-8, and similar).
6. Tests containing `↵`/`←`/`⇔` markers are excluded by default.
7. Rare keys `toke`/`note`/`also`/`emit`/`json`/`tree` are ignored.
8. This file documents the source, the fixed commit, and the filtering rules.

Result of the filtering: 129 test cases from 128 files are whitelisted
(69 positive, 60 error tests).
