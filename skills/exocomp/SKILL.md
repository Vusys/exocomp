---
name: exocomp
description: PHP and Laravel code intelligence via the `exocomp` CLI — one command per question, whole answers, every result carrying its own confidence. Use it instead of grep/sed for anything where PHP semantics decide the answer: finding a declaration's exact source, a class's real API through traits and parents, every reference to a class or member, the call graph in either direction, whether a call path exists between two methods, and structural search. Also answers Laravel questions no language tool can — a model's table, casts, accessors and relation targets, what a facade resolves to, a table's columns rebuilt from migrations, container bindings, the route table, where a job is dispatched and what handles it. Resolves magic members: a local scope called as `active()`, an accessor read as `$order->display_name`, `@method` and `@property` and `@mixin`, and a `__call` that forwards.
allowed-tools: Bash, Read
---

# exocomp

`exocomp <command> <name>` — names in, whole answers out. Use `vendor/bin/exocomp` when it is not on `PATH`.
It runs from anywhere inside the project (nearest ancestor with a `composer.json`); `--project <dir>` overrides.
Nothing needs building first and it never boots the application, so it works on a checkout with no `.env`,
no database and no `vendor/`.

## Reach for it instead of grep

| Question | Command | Why not grep |
| --- | --- | --- |
| Show me this method | `show 'App\Services\OrderService::place'` | no line-guessing, no `sed -n` second step, docblock included, never silently short |
| What's in this file | `outline app/Models/Order.php` | signatures and line spans without reading bodies |
| What can I call on this class | `members 'App\Models\Order'` | most members come from parents and traits, not the file you'd read |
| Does this name exist, and where | `locate Order` | answers "which of these four spellings is real" in one call, not four ORed searches |
| Who uses this / who calls this | `references-to` / `callers-of` | a same-named method on an unrelated class is a false positive; on a subclass it is a hit grep can't see |
| Who calls this scope or accessor | `references-to 'App\Models\Order::scopeActive'` | callers write `active()`; the declared name appears only at the declaration |
| What does this method call | `calls-from 'App\Jobs\ProcessOrder::handle'` | resolves each receiver's type to a real target |
| Is there a path from here to there | `reaches <from> <to>` | crosses dispatch, facade and container boundaries no call site names |
| What's near this method | `graph 'App\Http\Controllers\OrderController::store'` | bounded neighbourhood plus every edge it could not follow |
| Call sites matching a *shape* | `query '$X->write(...)' --arg-is-not-literal 0` | a text filter can't tell a docblock from a call, or a literal from a variable |
| Plain strings, config values, non-PHP, comments | **grep** | no semantics needed; exocomp adds nothing |

## Addressing

Names, never cursors or byte offsets. Quote them — the shell eats backslashes otherwise.

```
App\Services\OrderService              a class, interface, trait or enum
App\Services\OrderService::place       a method (case-insensitive, as PHP is)
App\Models\Order::$table               a property, including a promoted one
App\Models\Order::CREATED_AT           a class constant or enum case
```

`show`, `outline`, `imports` and `members` also take a file path. `locate`, `model`, `dispatches` and
`facade` take a short name (`Order`) as well as a fully-qualified one.

## Reading the answer

**Exit codes are meaningful: `0` found, `1` not found, `2` error.** So `exocomp locate Foo >/dev/null && …`
is a real predicate. A `1` from `reaches` or `query` is an answer, not a failure — don't retry it.

**Every record leads with `file:line:col`.** Cuttable, awkable, clickable.

**Every result carries a confidence, and it is the point of the tool:**

- `resolved` — the type is declared and checked. Safe to rewrite mechanically.
- `inferred` — from a docblock, or a receiver typed as a base class. A claim about the code, not a fact of it.
- `textual` — the name matches, the receiver could not be established.

**Anything it will not stand behind is handed back rather than dropped**, in a trailing block that names the
command which would close the gap:

```
── 3 not listed ──
extends App\Models\Base     members not listed here — try: exocomp members App\Models\Base
```

Read that block. It is where "I couldn't see past here" lives, and treating an answer as complete coverage
without it is how an audit gets wrongly signed off.

**Nothing is ever truncated silently** — a capped result says `… 47 more (use --limit)`, and `{"elided": 47}`
in JSON. Cap deliberately with `--limit N` or `--max-tokens N`.

**Output shape never changes based on TTY.** Text is the default and is the denser format; `--json` (JSONL,
one record per line) is for `jq` and structural joins; `--format=paths` prints deduplicated file paths, for
piping into another tool.

## Language commands

| Command | Notes |
| --- | --- |
| `show <symbol>` | `--without-bodies` for the skeleton |
| `outline <file\|class>` | every declaration, no bodies |
| `members <class>` | resolved through traits, parents, interfaces; each entry says where it came from. `--own` for declarations only |
| `imports <file\|class>` | the `use` map, aliases resolved |
| `locate <name>` | `--kind=class\|interface\|trait\|enum\|function\|method\|property\|constant\|enum-case` |
| `subclasses` / `implementors` / `traits-users` `<name>` | transitive by default; `--direct` for one level |
| `overrides <symbol>` | every declaration of one member across the hierarchy, up and down |
| `references-to <symbol>` | finds call sites written under another name too (a scope, an accessor). `--resolved-only` emits `resolved` alone — the set a rename may rewrite; `--strings` also searches string literals (slower, every hit `textual`) |
| `callers-of <symbol>` / `calls-from <symbol>` | the call graph, one step, either direction |
| `query <pattern>` | structural search — see below |
| `check [rules.json]` | saved rules against a baseline; exits 1 on anything new |

`show`, `outline` and `imports` take `--rev <SHA>` to answer about a past revision, and `--stdin` (with
`--as <path>`) to analyse a buffer you already hold. Corpus-wide commands refuse `--rev` rather than
ignoring it.

If a name can't be placed, `--scan` reads every file to find it — over a second on a large project, so it is
opt-in, and the answer says when it declined to take it.

## Laravel commands

Applies when `composer.json` requires `laravel/framework`, or `artisan` sits beside `app/`. All of it is
parsed out of ordinary PHP and SQL — the application is never booted, so these work on a branch you can't run.

| Command | Notes |
| --- | --- |
| `model <class>` | table, attributes, casts, fillable/guarded/hidden, **accessors**, **relations and their target classes**, global scopes, traits, and the `@property` block — each fact saying which class it came from. Reconciles the annotations against the schema, casts and accessors, and names the claims nothing backs. `--only=relation` etc. |
| `facade <Facade[::method]>` | what actually runs, through the accessor and the container, and where the method is declared |
| `schema [table]` | columns and indexes rebuilt from the squashed dump plus every migration since; omit the table to list them all |
| `config <key>` | where a dotted key is defined and everywhere it is read. `--defined` skips the corpus scan |
| `bindings [filter]` | container bindings, interface to concrete, contextual ones included |
| `routes [filter]` | URIs resolved through every enclosing group prefix. `--method=POST`, `--middleware` |
| `dispatches <class>` | where a job or event is dispatched, and what receives it |
| `morph-map [name]` | the alias map — the strings living in your `*_type` columns, in either direction |

```console
$ exocomp model Order
App\Models\Order   app/Models/Order.php:13

attributes (2)
app/Models/Order.php:17  table                  shop_orders
app/Models/Order.php:19  connection             shop

relations (3)
app/Models/Order.php:30  customer               belongsTo  App\Models\Customer
app/Models/Order.php:35  lines                  hasMany  App\Models\OrderLine
app/Models/Order.php:40  comments               morphMany  App\Models\Comment

global scopes (1)
app/Models/Concerns/HasTenant.php:17  App\Scopes\TenantScope   registered by App\Models\Concerns\HasTenant
```

That global scope is the kind of fact worth asking for before writing a query: nothing in a call site says it
is there.

## Magic members

Members that exist at runtime and are declared nowhere the call site can see. Most of them *are* written
down, just elsewhere, and the answer says which kind of evidence it found — which is what makes it
actionable.

| What you write | What it resolves to | Confidence |
| --- | --- | --- |
| `Order::active()` | `Order::scopeActive()` | `resolved` |
| `$order->display_name` | `getDisplayNameAttribute()`, or `displayName(): Attribute` | `resolved` |
| `$order->customer` | `Order::customer()`, the relation method | `inferred` |
| `$repo->fetch()` with `@method` on the class | the annotated member | `inferred` |
| `$bag->send()` with `@mixin Notifier` | `Notifier::send()` | `inferred` |
| `$fwd->send()` where `__call` forwards to a typed property | the target's method | `inferred` |
| a macro | *where it was registered*, never a target | `inferred` |
| `$bag->anything()` with a bare `__call` | nothing — reported with the reason | `textual` |

**Conventions reach `resolved` because the transform is a total function of the name and the target was
found and checked** — but only after confirming the installed framework still implements the convention.
Where the framework can't be read they degrade to `inferred` and say so in the reason.

**Annotations never reach `resolved`, by construction.** An annotation is a claim about the code rather than
a fact of it, and a great many are generated. The reason names the file the claim came from, so "inferred
through a generated helper" reads differently from "inferred through a class in `app/`".

**The inverse direction is the one to remember before a rename.** Nothing writes `scopeActive` except the
declaration:

```console
$ exocomp references-to 'App\Models\Order::scopeActive'
3 references to App\Models\Order::scopeActive  (3 resolved, 0 inferred, 0 unresolved)
app/Models/Order.php:70:5            public function scopeActive($query) { return $query; }
app/Services/OrderQueries.php:21:16  Order::active()
app/Services/OrderQueries.php:27:16  $order->active()
```

**A call target is verified, not assumed.** A member absent from a class read to the end of its ancestry is
`textual` with the reason — it is either a broken call or a magic one. On a class whose ancestry leaves the
corpus (any framework subclass) an absence proves nothing, so the answer is unchanged.

**When the annotations and the schema disagree**, `model` says so — it holds four accounts of a model's
attributes (migrations, `$casts`, accessors, `@property`) and names the claims nothing else backs. Generated
blocks go stale silently and nothing else in a PHP toolchain will tell you.

## Reachability

`reaches <from> <to>` asks whether a call path exists — the shape of a tenant-scoping audit, a
cascade-deletion audit, or "does anything under this prefix write to that table".

```console
$ exocomp reaches 'App\Http\Controllers\OrderController::store' 'App\Models\Order::save'
App\Http\Controllers\OrderController::store reaches App\Models\Order::save in 3 steps; the weakest step is resolved
app/Http/Controllers/OrderController.php:35:9  method call  →  App\Services\OrderService::place
app/Services/OrderService.php:15:9             dispatch  →  App\Jobs\ProcessOrder::handle
app/Jobs/ProcessOrder.php:19:9                 method call  →  App\Models\Order::save
```

Every step carries its own confidence and **the path is worth its weakest one** — one arriving through a
container binding is a different claim from one arriving through five declared calls.

The destination is a shape, not an address:

```
App\Models\Order::save     that member of that class
App\Models\Order           anything on that class
::save                     that member on any class
App\Models\*::delete*      globs in either half
```

The start can be a route, so the question can be asked in the terms it was thought in — and a pattern
matching several endpoints sweeps them all in one invocation, one verdict each:

```console
$ exocomp reaches 'route:POST /orders' 'App\Models\Order::save'
$ exocomp reaches 'route:*' 'App\Models\Order::save'
1 of 8 starting points reach App\Models\Order::save
routes/web.php:18:1  POST /orders                reached in 3 steps
routes/api.php:9:1   GET /api/orders             not reached
…
```

**A negative is a claim about a whole graph, so read what the negative says about itself.** Exit code 1, plus
one of: the walk ran out of graph rather than budget and followed every edge it met (the strongest a "no"
gets); or a `--depth` / `--max-nodes` budget cut it short; or edges led into a dependency (`--through-vendor`
follows them); or some calls could not be resolved at all, each listed with its address and reason. Only the
first means "there is no path". The others mean "not within what I looked at" — widen and ask again.

`graph <symbol>` is the same walk without a destination: everything within N steps outward, with the edges it
could not follow. Use it to orient in unfamiliar code. Both take `--depth` (6), `--max-nodes` (1500) and
`--through-vendor`.

## Structural search

`query` takes the code shape you want, written as PHP that has to parse. Not literal: `$UPPERCASE` for any
node, `_UPPERCASE` for any name, `$_` for any node bound to nothing, `...` for any arguments, `()` for any
parameters, `{}` for any body.

```console
$ exocomp query '$R->findByUuid($U)' --in 'App\Repositories\**'
$ exocomp query 'writeMetric(...)' --arg-is-not-literal 0
$ exocomp query 'function _M() {}' --annotated @deprecated --visibility public
```

Predicates: `--in` / `--not-in`, `--annotated`, `--has-attribute`, `--arg-is-literal` /
`--arg-is-not-literal` (0-based), `--param-count`, `--visibility`, `--returns`, `--uses-trait`, `--extends`,
`--implements`, `--receiver-type` / `--receiver-implements` / `--receiver-extends`. `--capture NAME` prints
just one binding. All syntactic except the three receiver predicates, which need inference — a receiver whose
type can't be established does not match, so a scoped audit stays scoped.

`check` turns a sweep into a guard: rules as JSON (every `query` predicate is available as a key), a baseline
of what already fails, exit 1 on anything new. Baseline entries are keyed by rule, file, enclosing
declaration and matched code — never by line number.

## Recipes

```bash
# Before a rename: what may be rewritten, and what must be judged by hand.
exocomp references-to 'App\Models\Order::save' --resolved-only
exocomp references-to 'App\Models\Order::save'          # then read the inferred/textual groups

# Renaming a scope or an accessor: the call sites write a different name entirely.
exocomp references-to 'App\Models\Order::scopeActive'   # finds every `->active()` too

# Which of a model's annotated properties nothing backs — a stale generated block.
exocomp model Order | grep 'accounted for by no column'

# The real API of a class, without opening its four parents.
exocomp members 'App\Models\Order' | grep -i tenant

# Which endpoints touch a model at all.
exocomp reaches 'route:*' 'App\Models\Order'

# What a facade call actually runs, before changing it.
exocomp facade 'Storage::disk'

# Every file involved in a change, for a follow-up pass.
exocomp references-to 'App\Models\Order' --format=paths | xargs …

# Structural join: which deprecated methods still have callers.
exocomp query 'function _M() {}' --annotated @deprecated --json | jq -r .fqn |
  while read -r m; do exocomp callers-of "$m" --json; done
```

## What it declines — don't ask it these

It says so in the answer rather than guessing, and the honest gap is the feature. Do not paper over one with
a grep and present the result as complete.

- **A `__call` that computes its target** — one that parses a prefix, or reads out of an array. A
  single-statement forwarder to a typed property is followed one step; anything more involved is declined,
  because following it would mean evaluating the method. Also **string callables, `call_user_func` with a
  computed callable, `$obj->$method()`, variable class names, `extract()`, `class_alias()`**.
- **Eloquent's builder API.** `Order::where(...)->update(...)` runs on a builder reached by magic
  forwarding: the walk reports the call, stops, and says the ancestry left the readable source — it does not
  claim the method is missing. Local scopes and accessors *are* resolved; the framework's own query methods
  are not.
- **Middleware, observers and policies** — registration sites are readable, their edges are not modelled.
- **Dependencies, by default.** An edge *into* `vendor/` is recorded and checked against the destination, so
  a path ending at a framework method is found; walking onward is `--through-vendor`.
- **A widely implemented interface** fans out rather than leads somewhere; the walk follows twelve
  implementations and says when it stopped.
- **`morphTo`** names no class — what it points at is decided by the morph map, not the file.
- **A binding whose concrete side is a closure** is followed exactly one step (a closure returning one
  `new X` is that X); anything more involved is declined.
- **Schema** is the *source's* account, never the database's — every row `inferred`, and a table whose
  history includes raw SQL is reported as incomplete rather than quietly short a column.
- It is **not an LSP** (no completion, hover, or cursor addressing), **not a static analyser** (use PHPStan),
  not a formatter, not Rector, not a test runner, and **has no write side yet** — it does not edit files.
