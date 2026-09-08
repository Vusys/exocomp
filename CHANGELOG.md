# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Phase 0 — the four read commands**, over `nikic/php-parser` with no index, no daemon, no
  type inference and no framework profile.
  - `show <symbol>` — the exact source of a named class, method, property or constant,
    including its docblock; `--without-bodies` for the skeleton.
  - `outline <file|class>` — every declaration with signature, visibility, return type and
    line span, no bodies.
  - `members <class>` — the members a class declares, with what is *not* listed handed back
    alongside the command that would list it.
  - `imports <file|class>` — the `use` map with aliases resolved.
- **The output contract**, from the first commit rather than retrofitted: text by default with
  `--json` opt-in, `file:line:col` leading every record, visible truncation via `--limit` and
  `--max-tokens`, a `resolved`/`inferred`/`textual` confidence on every result, name-based
  addressing, and exit codes 0 (found) / 1 (not found) / 2 (error).
- `--scan`, for the last-resort content search of the source roots.
- **Fixture tiers A, B and F**, with golden-file snapshots of every command. Tier A carries
  PHP 8.4 property hooks and asymmetric visibility as the standing regression guard on
  language-version currency.

### Changed

- Composer's classmap is now loaded lazily, after PSR-4 arithmetic rather than before it.
  Measured on a 950k-line Laravel application, the dumped classmap contains no application
  classes at all unless `dump-autoload -o` was used, so loading it first cost 33 ms — over a
  third of the tool's working time — to answer questions it could not help with.
- The content scan of the source roots is opt-in behind `--scan` when a classmap exists. It
  cost 1.3 seconds and was being paid on misses, where the slowest answer is "no". Without a
  classmap it still runs unasked, since nothing else is left to try. When skipped, the tool
  reports that it declined and names the flag.
- Anonymous classes appear in `outline`'s trailing block with their line and member count.
  They cannot be addressed by name, so they are not listed as though they could be — but
  omitting them entirely meant an outline could silently drop several methods.
- Dense property signatures carry scalar, `true`/`false`/`null` and constant defaults, because
  for a table name or a lookback window the value is the answer.

### Fixed

- A capped default value is cut on a character boundary rather than a byte one. Cutting by
  bytes split multi-byte characters in half, leaving output that was not valid UTF-8.
- `--json` no longer emits an empty object when a record cannot be encoded. It substitutes the
  offending bytes and says so, falling back to an address-plus-error record — an empty record
  in a stream is indistinguishable from one that was dropped.
