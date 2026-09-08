# exocomp

Agent-first PHP code intelligence. Names in, whole answers out, in as few tokens as the answer
takes.

```console
$ exocomp show 'App\Services\OrderService::handle'
app/Services/OrderService.php:140-158  App\Services\OrderService::handle
    /**
     * Places the order and hands it to the repository.
     */
    public function handle(Order $order): void
    {
        $this->repo->save($order);
    }
```

## Why

An audit of 131,554 shell commands from months of real PHP work found 3,577 searches over PHP
source and **zero** uses of the purpose-built PHP refactoring engine that was installed, on
`$PATH`, and documented in a prompt telling the agent to prefer it. It lost to `grep` on
time-to-first-answer, shell composability, output density, and its addressing model — not on
capability.

Three failure modes account for most of those 3,577 searches, and this tool exists to collapse
them:

- **The find-then-read dance.** 1,886 `grep -n` calls followed by 1,246 `sed -n 'N,Mp'` reads
  and 543 guesses at a context size. When the guess is short the answer is silently truncated,
  and a truncated method body reads as a complete one.
- **Candidate-name guessing.** 661 searches OR'd four or more plausible method names together
  to find out which existed.
- **Predicate chaining.** 2,170 commands with three or more pipe stages — structural questions
  approximated by text filters.

## Install

```console
composer require --dev vusys/exocomp
```

Or build a single-file PHAR with [Box](https://github.com/box-project/box): `box compile`
writes `builds/exocomp.phar`, which runs anywhere with PHP 8.4 and no other setup.

## Commands

This is Phase 0: a parser and nothing else. No index, no daemon, no type inference, no
framework profile. It works on a fresh checkout with no `vendor/` and asks nothing of you
before it answers.

| Command | Answers |
| --- | --- |
| `show <symbol>` | The exact source of a named class, method, property or constant, docblock included. `--without-bodies` for the skeleton. |
| `outline <file\|class>` | Every declaration in a file: signatures, visibility, return types, line numbers. No bodies. |
| `members <class>` | The members a class declares, with the line each is on. |
| `imports <file\|class>` | The `use` map, with aliases resolved. |

Symbols are addressed by name, never by cursor or byte offset:

```
App\Services\OrderService              a class, interface, trait or enum
App\Services\OrderService::handle      a method (case-insensitive, as PHP is)
App\Models\Order::$table               a property, including a promoted one
App\Models\Order::CREATED_AT           a class constant or enum case
```

## The output contract

These hold across every command, and they are the interface as much as the commands are.

- **Text by default, `--json` when you want it.** JSONL costs 182% more characters than the
  equivalent text for the same records, and wrapping source in JSON escapes every newline. Text
  is what a reader gets; JSON is for `jq`, CI, and structural joins.
- **Every record leads with `file:line:col`.** The same convention grep established, clickable
  in most terminals, and still parseable by `cut` and `awk`.
- **Nothing is ever truncated silently.** A capped result says `… 47 more (use --limit)` in
  text and `{"elided": 47}` in JSON. A silently truncated answer reads as complete coverage,
  which is how an audit gets wrongly signed off.
- **Every result carries a confidence** — `resolved`, `inferred` or `textual`. Anything the
  tool will not stand behind goes in a trailing block with the reason, and the reason names the
  command that would close the gap:

  ```
  ── 3 not listed ──
  extends App\Models\Base     members not listed here — try: exocomp members App\Models\Base
  ```

- **Meaningful exit codes**: `0` found, `1` not found, `2` error. So `&&` chains and CI guards
  mean something.
- **The structure never changes based on whether output is a terminal.** An agent's output is
  always captured; format-switching on TTY detection would hand it something other than what is
  documented here.

Shared options: `--project`, `--format=text|json|paths`, `--json`, `--limit`, `--max-tokens`,
`--scan`.

Names are resolved with no index: PSR-4 arithmetic first, then Composer's classmap, then a
filename match. On a 950k-line Laravel application that places 99.9% of application classes, and
every candidate is verified against the parsed declaration before it is returned, so a stale
classmap produces a miss rather than a wrong answer. The last resort — reading every file to
find a class that shares one with a differently-named sibling — costs over a second, so it is
behind `--scan`, and the tool says when it declined to take it.

## What it does not do

Not an LSP — no completion, hover or signature help, and no cursor addressing. Not a static
analyser; PHPStan and Psalm are better and already exist. Not a formatter, not Rector, not a
test runner. It emits joinable output and lets the shell compose.

## Development

```console
composer test        # PHPUnit
composer check       # Pint --test, Rector --dry-run, PHPStan level 9
composer infection   # mutation testing
```

`tests/Fixtures` is excluded from Pint, Rector and PHPStan, and that exclusion is load-bearing:
most of the corpus is deliberately pathological code, and a formatter that tidies a naming trap
deletes the thing under test. See `tests/Fixtures/corpus/README.md`.
