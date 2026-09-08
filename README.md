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

No framework profile yet, and no write side. It works on a fresh checkout with no `vendor/` and
asks nothing of you before it answers.

| Command | Answers |
| --- | --- |
| `show <symbol>` | The exact source of a named class, method, property or constant, docblock included. `--without-bodies` for the skeleton. |
| `outline <file\|class>` | Every declaration in a file: signatures, visibility, return types, line numbers. No bodies. |
| `members <class>` | A class's whole API, resolved through its traits, parents and interfaces, each entry saying where it came from. `--own` for declarations only. |
| `imports <file\|class>` | The `use` map, with aliases resolved. |
| `locate <name>` | Where a short name or a fully-qualified one is declared. |
| `subclasses` / `implementors` / `traits-users` | The inheritance graph, transitively. |
| `overrides <symbol>` | Every declaration of one member across its hierarchy, up and down. |
| `references-to <symbol>` | Every reference to a class or member, grouped by how certain each one is. |
| `callers-of` / `calls-from` | The call graph in either direction, with unresolvable edges reported rather than dropped. |
| `query <pattern>` | Structural search: the code shape you are looking for, written as PHP. |
| `check [rules.json]` | Run saved rules against a baseline and fail on anything new. |
| `index --status` / `--warm` | Maintenance. Nothing else requires it. |

Every read command also takes `--rev <SHA>`, to answer about a past revision rather than the
working tree, and `--stdin`, to analyse a buffer you already hold.

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

## References, and knowing what you don't know

```console
$ exocomp references-to 'App\Notifications\Notifier::send'
9 references to App\Notifications\Notifier::send  (7 resolved, 1 inferred, 1 unresolved)
...
inferred (1)
app/Http/Controller.php:51:16  $thing->send('hello')

── 1 unresolved ──
app/Legacy/Bridge.php:36:16    $anything->send('hello')   receiver type unknown
```

Two judgements matter more than finding the name, and grep can make neither:

- **A same-named method on an unrelated class is excluded.** Without that, the tool is `sed`
  with extra steps, and every rename it drives is wrong.
- **A same-named method on a subclass is included.** Without that, a rename silently breaks
  polymorphism — and in PHP it breaks at runtime, on whichever path happens to execute, not at
  build time.

Everything else goes in the trailing block with the reason. A rename with 98% recall is not 98%
good, it is broken, so the useful output is not a list of references but a list plus an honest
account of what is missing from it. Only `resolved` may ever be rewritten mechanically.

Inference is deliberately narrow: declared parameter, property and return types, promoted
properties, `$this`/`self`/`static`/`parent`, `new X`, assignment from an already-resolved call,
enum cases, and docblocks — which are always `inferred`, never `resolved`, because an
annotation is a claim about the code rather than a fact of it. Anything else is reported as
unknown with a reason. That is not a gap to be closed later; it is what makes partial inference
safe to ship.

## Structural query

`query` takes the code you are looking for, written as PHP, with three things that are not
literal: `$UPPERCASE` for any node, `_UPPERCASE` for any name, and `...` / `()` / `{}` for any
arguments, parameters or body.

```console
$ exocomp query '$X->recordUsage(...)' --arg-is-not-literal 0
```

That is the audit's own worked example. Written as text it was four chained filters —
`grep -rn 'recordUsage(' | grep -v "recordUsage('" | grep -v 'recordUsage("' | grep -v
'function recordUsage'` — trying to express "call sites where the first argument is not a
literal string". On a 950k-line codebase that returns 12 lines, **of which two thirds are
comments mentioning the method**, because a text filter cannot tell a docblock from a call. The
structural form returns the 3 real call sites, in 83% fewer bytes, with nothing to eyeball.

Predicates: `--in` / `--not-in`, `--annotated`, `--has-attribute`, `--arg-is-literal` /
`--arg-is-not-literal`, `--param-count`, `--visibility`, `--returns`, `--uses-trait`,
`--extends`, `--implements`. All syntactic — nothing is inferred, so nothing is guessed.

**`check` turns a sweep into a guard.** Save rules as JSON, record a baseline of what already
fails, and only new violations fail the build. Baseline entries are keyed by rule, file,
enclosing declaration and the matched code — never by line number, so an unrelated edit above a
waived violation does not cry wolf, and a guard that cries wolf gets switched off.

## The index

There is a symbol cache, and it is never a precondition. Commands that can answer from the
parser alone do; the ones that need the corpus bring the cache up to date themselves rather than
telling you to run a build step first. That refusal is the specific thing that stopped the
obvious prior art from ever being reached for.

It is **content-addressed and shared between checkouts**. Facts belong to a file's contents
rather than to its path, so a second worktree of the same commit costs a directory walk instead
of a parse. Measured on a 950k-line Laravel application: **14.0 s to build the first time, 1.3 s
for a second checkout of the same content** — 6,536 files, none of them re-parsed.

Freshness is checked against the answer, not the corpus. Whatever files a result came from are
stat-ed and re-read if they have moved on; a query that finds *nothing* escalates to one full
pass, because a newly written class and a class that does not exist look identical from a stale
index. Walking the whole tree first would be the obvious alternative and it costs 83 ms on that
project — most of a latency budget spent proving that files nobody asked about have not moved.

## What it does not do

Not an LSP — no completion, hover or signature help, and no cursor addressing. Not a static
analyser; PHPStan and Psalm are better and already exist. Not a formatter, not Rector, not a
test runner. It emits joinable output and lets the shell compose.

## Development

```console
composer test        # PHPUnit
composer check       # Pint --test, Rector --dry-run, PHPStan level 9
composer sweep       # exocomp's own rules, against its own baseline
composer infection   # mutation testing
```

`composer sweep` runs the tool against itself. Its two rules exist because both caught real
bugs here: `array_filter()` with no callback silently drops the string `"0"`, and `substr()`
cuts on a byte boundary and can split a multi-byte character. Existing safe uses are baselined,
so the sweep fails only on new ones.

`tests/Fixtures` is excluded from Pint, Rector and PHPStan, and that exclusion is load-bearing:
most of the corpus is deliberately pathological code, and a formatter that tidies a naming trap
deletes the thing under test. See `tests/Fixtures/corpus/README.md`.
