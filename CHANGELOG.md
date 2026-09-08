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
- **Fixture tiers A, B and F**, with golden-file snapshots of every command. Tier A carries
  PHP 8.4 property hooks and asymmetric visibility as the standing regression guard on
  language-version currency.
