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

And a fourth that no language-only tool addresses at all: **the questions are framework
questions.** 678 searches were about facades, 307 about config keys, 139 about model internals,
108 about reconstructing a table from its migrations. A language engine can tell you a relation
method exists; it cannot tell you what class the relation targets, that the model carries a
tenant-scoping global scope, or that `dispatch(new ProcessOrder)` continues in another class.

## Install

```console
composer require --dev vusys/exocomp
```

Or build a single-file PHAR with [Box](https://github.com/box-project/box): `box compile`
writes `builds/exocomp.phar`, which runs anywhere with PHP 8.4 and no other setup.

## Commands

No write side yet. It works on a fresh checkout with no `vendor/`, and asks nothing of you
before it answers — in particular it never boots your application.

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
| `reaches <from> <to>` | Whether a call path exists — from a method, or from every matching endpoint at once. Exits 1 when there is none. |
| `graph <symbol>` | Everything within N steps outward, and every edge that could not be followed. |
| `query <pattern>` | Structural search: the code shape you are looking for, written as PHP. |
| `check [rules.json]` | Run saved rules against a baseline and fail on anything new. |
| `index --status` / `--warm` | Maintenance. Nothing else requires it. |

And, for a Laravel application, a framework profile — everything below is derived by parsing
ordinary PHP and SQL, never by booting anything:

| Command | Answers |
| --- | --- |
| `model <class>` | An Eloquent model's table, attributes, casts, relations **and their target classes**, global scopes and traits — resolved through parents and traits, each fact saying which class it came from. |
| `facade <Facade[::method]>` | The class a facade actually resolves to, through its accessor and the container, and where the method is declared on it. |
| `schema [table]` | A table's columns and indexes, reconstructed from the squashed schema dump and every migration since. |
| `config <key>` | Where a config key is defined and everywhere it is read. |
| `bindings [filter]` | Container bindings, interface to concrete, including contextual ones. |
| `routes [filter]` | The route table, with URIs resolved through every enclosing group prefix. |
| `dispatches <class>` | Where a job or event is dispatched, and what receives it. |
| `morph-map [name]` | The polymorphic alias map — the strings stored in your `*_type` columns. |

The profile applies when `composer.json` requires `laravel/framework`, or when `artisan` sits
beside an `app/` directory. On anything else these commands say so rather than guessing.

`show`, `outline` and `imports` also take `--rev <SHA>`, to answer about a past revision rather
than the working tree, and `--stdin`, to analyse a buffer you already hold. The corpus-wide
commands refuse `--rev` rather than ignoring it: the index describes the working tree, so
answering about today while claiming to answer about then would be worse than not answering.

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

## Framework answers, and what they decline

The Laravel profile reads files. It never boots the application, because the disposable
worktrees this tool is built for routinely have no working `.env` and no reachable database —
and a profile that only works in a fully provisioned checkout is unavailable exactly when
someone is spelunking an unfamiliar branch.

Reading rather than booting has limits, and every one of them is stated in the answer rather
than papered over:

```console
$ exocomp model Comment
App\Models\Comment   app/Models/Comment.php:10

attributes (1)
app/Models/Comment.php:10  table    comments   derived from the class name, and the migrations do create this table

── 1 not read from the source ──
app/Models/Comment.php:16  commentable  morphTo   polymorphic — what it points at is decided by the morph map, not by this file
```

- A **table name** that is declared is `resolved`. One derived from the class name is `inferred`
  and is then checked against the tables the migrations actually create, so "the convention says
  `people` and a table called `people` exists" does not read the same as the convention alone.
  The derivation uses `doctrine/inflector`, the library the framework itself uses, because a
  hand-rolled pluraliser measured 92.5% agreement against 306 real model names — and the names
  it got wrong are exactly the ones nobody can guess.
- A **relation** whose target is `X::class` is `resolved`; one named by a string is `inferred`;
  a `morphTo` names no class at all and is declined.
- A **binding** whose concrete side is a closure is followed exactly one step — a closure
  returning a single `new X` is that X. Anything more involved is declined, because evaluating a
  factory is where a static reader starts inventing answers.
- A **facade**'s core alias table is parsed out of `registerCoreContainerAliases()` in the
  framework you have installed, located through Composer's autoload map. Nothing is hardcoded: a
  baked-in "Storage means FilesystemManager" would be right until the framework changed and
  would then be wrong without saying so.
- A **schema** is reconstructed from the squashed dump plus every migration since, in that
  order. It is the source's account of the schema, never the database's, so every row is
  `inferred`; a table whose history includes raw SQL is reported as incomplete rather than
  quietly short a column.
- A **listener** discovered by the type of its `handle()` parameter is `inferred`, because
  whether discovery is switched on is not something the source says. One declared in `$listen`
  is `resolved`.
- A **morph map**'s keys live in your database, in the `*_type` column of every polymorphic
  relation. `morph-map` says so on every answer, because a rename that updates the code and
  stays silent about `commentable_type` has made the application worse.

## Reachability, and what a "no" is worth

`reaches` answers the question the rest of the engine was built for: is there a call path from
here to there. A tenant-scoping audit, a cascade-deletion audit, "does anything under this
prefix write to that table" — each is that question asked once per endpoint, and by hand it is
a day of opening files.

```console
$ exocomp reaches 'App\Http\Controllers\OrderController::store' 'App\Models\Order::save'
App\Http\Controllers\OrderController::store reaches App\Models\Order::save in 3 steps; the weakest step is resolved
app/Http/Controllers/OrderController.php:35:9  method call  →  App\Services\OrderService::place
app/Services/OrderService.php:15:9             dispatch  →  App\Jobs\ProcessOrder::handle
app/Jobs/ProcessOrder.php:19:9                 method call  →  App\Models\Order::save
```

Three hops, one of them across a queue boundary that no call site names. Every step carries its
own confidence, and the path is worth its weakest one — a route that arrives through four
resolved calls and one container binding is a different claim from one that arrives through
five resolved calls, and collapsing them would hide exactly the step worth checking.

The destination is a shape rather than an address, because the question is rarely about one
method:

```
App\Models\Order::save      that member of that class
App\Models\Order            anything on that class
::save                       that member on any class
App\Models\*::delete*       globs in either half
```

**The sweep is one call.** `route:<pattern>` starts from every matching endpoint, so an audit
that was one invocation per route is one invocation:

```console
$ exocomp reaches 'route:*' 'App\Models\Order::save'
1 of 8 starting points reach App\Models\Order::save
routes/api.php:9:1   GET /api/orders             not reached
routes/web.php:9:1   GET /admin/orders           not reached
routes/web.php:18:1  POST /orders                reached in 3 steps
…
```

Measured on a 950,000-line application: a hundred endpoints swept in 22 seconds. One symbol's
path in about four, and a negative one that exhausts a small subgraph in a quarter of a second.
These are far above the sub-200 ms the index answers in, for the same reason the other framework
commands are — a walk parses files by convention rather than reading rows, and nothing about a
walk is cached between runs yet.

### The negative answer

A "no" is a claim about a whole graph, and a graph of a language with dynamic dispatch is never
complete. An unqualified "no path" would be the confidently-wrong answer this tool exists not
to give, so every negative says how much it is worth:

```console
$ exocomp reaches 'App\Services\ReportBuilder::build' 'App\Models\Order::save'; echo "exit $?"
no path from App\Services\ReportBuilder::build to App\Models\Order::save; 1 declaration was walked
note: the walk ran out of graph rather than out of budget, and every edge it met was followed —
      so within the edges this tool models, there is no path
exit 1
```

That note is the strongest thing a negative can say, and it is only printed when it is true.
When it is not, the answer says which of these applied instead: a `--depth` or `--max-nodes`
budget cut the search short, some edges led into a dependency and were not walked past
(`--through-vendor` follows them), or some calls could not be resolved at all — each listed
with its address and reason.

Dead ends that are not gaps are deliberately left out of that list. A walk that stops at
`str_ends_with` has lost nothing; a walk that stops because a receiver's type is unknown has
lost a subtree of unknown size. Listing both would bury the second among the first.

### What it still declines

- **Eloquent's magic forwarding.** `Order::where(...)->update(...)` runs on a query builder
  reached through `__call` on a framework base class. The walk reports the call and stops, and
  says the ancestry left the readable source rather than claiming the method does not exist.
- **`__call` and `__callStatic` generally**, string callables, and anything whose target is
  computed at runtime.
- **Middleware, observers and policies.** Their registration sites are readable and their edges
  are not modelled yet; §3.4 lists them and they are not built.
- **Dependencies**, by default. The edge into one is recorded and checked against the
  destination, so a path that *ends* at a framework method is found; walking onwards from there
  is `--through-vendor`.
- **A widely implemented interface** fans out rather than leads somewhere. The walk follows
  twelve implementations and says so when it stops.

## What it does not do

Not an LSP — no completion, hover or signature help, and no cursor addressing. Not a static
analyser; PHPStan and Psalm are better and already exist. Not a formatter, not Rector, not a
test runner. It does not boot your application, run migrations, or talk to your database. It
emits joinable output and lets the shell compose.

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
