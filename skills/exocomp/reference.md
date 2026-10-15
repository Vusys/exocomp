# exocomp reference

The detail behind `SKILL.md`: every command, how to read an answer, and what the tool declines. Each command also documents itself: `exocomp <command> --help`.

## Addressing

Names first. A name survives an edit and a byte offset does not, so every address is a name — with one
narrow exception: the *file* half may be a path, because the member half is still a name and the address
still survives. Quote them — the shell eats backslashes otherwise.

```
App\Services\OrderService              a class, interface, trait or enum
App\Services\OrderService::place       a method (case-insensitive, as PHP is)
App\Models\Order::$table               a property, including a promoted one
App\Models\Order::CREATED_AT           a class constant or enum case
```

`locate`, `model`, `dispatches` and `facade` take a short name (`Order`) as well as a fully-qualified one.

**When the path is what you have, use it.** `show`, `outline`, `imports` and `members` all take a file path,
and `show` takes a member off one — so there is never a reason to look a namespace up first, and never a
reason to fall back to `grep -n 'function x' -A 30 <file>`:

```
exocomp show 'app/Services/OrderService.php::place'     a member of whatever that file declares
exocomp show 'app/Services/OrderService.php'            the class it declares, if it declares one
exocomp show --at 'app/Services/OrderService.php:214'   the declaration covering that line
```

The member half is still a name, so the address survives edits above it. `--at` is for a position that came
out of a stack trace, a diff or a test failure; a position does not survive an edit, which is why it is a
flag rather than an address.

**This is the only way to read a Laravel migration.** A migration is `return new class extends Migration` —
an anonymous class, with no name for any command to take. Address it by its file:

```
exocomp show 'database/migrations/2024_01_01_000000_create_orders_table.php::up'
```

`show` takes several addresses at once, printed in the order asked for:

```
exocomp show 'App\Models\Order::place' 'App\Models\Order::cancel' 'App\Models\Base::save'
```

Prefer this to reading a line range once you know where the members are. A range is only correct while
every member you want sits in one file, contiguously, with nothing else between them — and it silently
stops being correct when one of them turns out to be inherited. Addresses have neither failure mode.

## Reading the answer

**Exit codes are meaningful: `0` found, `1` not found, `2` error** — and a command line that does not parse is an error, with the nearest real option or command named. So `exocomp locate Foo >/dev/null && …`
is a real predicate. A `1` from `reaches` or `query` is an answer, not a failure — don't retry it.

**Every record leads with `file:line:col`.** Cuttable, awkable, clickable.

**Every result carries a confidence, and it is the point of the tool:**

- `resolved` — the type is declared and checked. Safe to rewrite mechanically.
- `inferred` — from a docblock, or a receiver typed as a base class. A claim about the code, not a fact of it.
- `textual` — the name matches, the receiver could not be established.

**A guessed namespace is corrected rather than refused.** Guessing a namespace is by a wide margin the
usual way a lookup fails. When exactly one class carries that short name, the question is answered with it
and the substitution is stated — so don't re-run, and don't pass `--scan`:

```
exocomp show 'App\Repositories\OrderRepository::find'
src/Domain/Orders/Persistence/OrderRepository.php:88-96  App\Domain\Orders\Persistence\OrderRepository::find
…
note: nothing is declared as "App\Repositories\OrderRepository" — answered App\Domain\Orders\Persistence\OrderRepository instead
```

Read that note: the answer is about a *different class* from the one you named, and whatever you write next
must use the real name. Where two classes share the short name there is nothing to choose between, so the
miss stands and both are named — that is the case where you pick one and ask again.

The corollary is that the namespace is optional when it is unambiguous: `exocomp show 'OrderRepository::find'`
is a legitimate address.

**Members get the same treatment.** A member the class does not declare itself but inherits from a parent or a trait is answered from where it is declared, with a note saying so. One it does not have at all is looked for on related classes: if exactly one subclass, implementor, used trait, or trait used by a subclass declares it, that is the answer (again with a note). Otherwise the miss stands, exits `1`, and every declaration of the name is listed, each marked with how it relates to the class you named. Unrelated classes are listed but never answered from. This applies to `show`, `members <class>::<member>`, `callers-of` and `references-to`.

**Anything it will not stand behind is handed back rather than dropped**, in a trailing block that names the
command which would close the gap:

```
── 3 not listed ──
extends App\Models\Base     members not listed here — try: exocomp members App\Models\Base
```

Read that block. It is where "I couldn't see past here" lives, and treating an answer as complete coverage
without it is how an audit gets wrongly signed off.

It is not the only spelling, so look for the right one. `members`, `reaches`, `graph` and a multi-address
`show` use the trailing block; `references-to` and `callers-of` count the same thing in the header
(`6 references to … (4 resolved, 2 inferred, 0 unresolved)`); a single-address `show` has nothing to add
because it either found the one thing you named or exited `1`.

**Nothing is ever truncated silently** — a capped result says `… 47 more (use --limit)`, and `{"elided": 47}`
in JSON. Cap deliberately with `--limit N` or `--max-tokens N`, **not by piping to `head`**: the cut is the
one thing the tool guarantees to tell you about, and `head` throws that guarantee away along with the
output it drops. It throws the exit code away too — `exocomp locate Foo | head -40; echo $?` prints `0`
after a miss, because the status of a pipeline is `head`'s.

**A ceiling of ~25,000 tokens applies by default whenever output is not a terminal**, and says so when it
bites. It is some eight times the largest answer ever measured, so it will not touch ordinary work; it is
there to stop a `members` on a god object or a `references-to` on a base class filling your context in one
call. `--max-tokens N` raises it, `--max-tokens 0` removes it.

**Output shape never changes based on TTY** — only the default ceiling above does, and colour: output that is not going to a terminal is plain text even when `FORCE_COLOR` is set (`--ansi` forces colour; `NO_COLOR` is honoured). Text is the default and is
the denser format; `--json` (JSONL, one record per line) is for `jq` and structural joins; `--format=paths`
prints deduplicated file paths, for piping into another tool.

## Language commands

| Command | Notes |
| --- | --- |
| `show <symbol>…` | several addresses at once; a file path is an address too; `--at file.php:line` for a position; `--without-bodies` for the skeleton. Exit `1` if any one is missing, and the missing are named |
| `outline <file\|class>` | every declaration, no bodies |
| `members <class>` | resolved through traits, parents, interfaces; each entry says where it came from. `--own` for declarations only; `--matching <regex>` filters by member name, and the header says how many the filter kept out of how many exist. `members <class>::<member>` narrows to that one member |
| `imports <file\|class>` | the `use` map, aliases resolved |
| `locate <name>…` | several names at once — "which of these exists, and where" is usually the real question. `--kind=class\|interface\|trait\|enum\|function\|method\|property\|constant\|enum-case` |
| `subclasses` / `implementors` / `traits-users` `<name>` | transitive by default; `--direct` for one level |
| `overrides <symbol>` | every declaration of one member across the hierarchy, up and down |
| `references-to <symbol>` | finds call sites written under another name too (a scope, an accessor). `--resolved-only` emits `resolved` alone — the set a rename may rewrite; `--strings` also searches string literals (slower, every hit `textual`) |
| `callers-of <symbol>` / `calls-from <symbol>` | the call graph, one step, either direction |

| `query <pattern>` | structural search — see below |
| `check [rules.json]` | saved rules against a baseline; exits 1 on anything new |

`references-to` and `callers-of` stop opening files after 60 seconds (`--time-limit N`, `0` for none) and answer with what they found. The header then says `incomplete, N files not searched` and a note repeats it, so treat the answer as a lower bound. On a terminal they draw a progress line on stderr; piped, they print nothing extra.

**More than ten unresolved hits are summarised by reason**: one line per reason with its count, three examples under each, and `--all-unresolved` to list every one. A search for a common name such as `create` meets thousands of `Model::factory()->create()` calls, and listed one per line they buried the answer above them. In `--json` each group is a `{"unresolved_reason": …, "count": N, "examples": 3}` record followed by its examples.

**Calls proved not to be on the target are excluded and counted** — `(…, 11666 excluded)` in the header, with a note giving the rule. Today there is one rule: a call on a value Eloquent's own API returned from a model, through framework methods only, when the target class has no ancestor outside the indexed source, so Eloquent cannot return one. Any callback, `::class` argument, value read out of the model, scope, macro or application method of that name on a framework subclass leaves the call unresolved instead.

**Container lookups are typed.** `app(X::class)`, `resolve(X::class)`, `app()->make(X::class)`, `$this->app->make(X::class)` and `App::make(X::class)` are an `X` (`resolved`), or the class a provider binds `X` to (`inferred`, since a binding can be changed at runtime).

`show`, `outline` and `imports` take `--rev <SHA>` to answer about a past revision, and `--stdin` (with
`--as <path>`) to analyse a buffer you already hold. Corpus-wide commands refuse `--rev` rather than
ignoring it.

If a name can't be placed, `--scan` reads every file to find it — over a second on a large project, so it is
opt-in, and the answer says when it declined to take it.

`locate` needs no such flag: it reads the source roots itself before returning any negative, so "nothing named
X is declared" means the bytes were read and it was not there, and the message says how big the index behind it
was. `--no-sync` skips that read — faster, and able to be wrong about a name added since the last full pass.

**The source roots are whatever Composer declares, plus what the framework loads by scanning.** On a Laravel
project that means `database/migrations/` and `routes/` are indexed and searchable alongside `app/` — so a
migration's methods, and the anonymous class holding them, are reachable by every command that takes a file.

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
exocomp members 'App\Models\Order'

# Only the part of it you care about. Use --matching rather than piping to grep: it says how many
# members the filter kept out of how many exist, so a narrow pattern cannot read as a small class.
exocomp members 'App\Models\Order' --matching 'tenant|contract'

# Then read the ones that matter — by name, not by line range. `members` says which class each
# came from, and that is the class to name here; two of these may live in different files.
exocomp show 'App\Models\Order::place' 'App\Models\Order::cancel' 'App\Models\Base::save'

# Which endpoints touch a model at all.
exocomp reaches 'route:*' 'App\Models\Order'

# What a facade call actually runs, before changing it.
exocomp facade 'Storage::disk'

# A line number out of a stack trace or a failing test, turned into the declaration around it.
exocomp show --at 'app/Services/OrderService.php:214'

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
  are not. A reference search can still rule such a call *out* (see the exclusion rule above), but never in.
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
- It is **not an LSP** (no completion, no hover; `show --at file:line` resolves a position but nothing takes
  a live cursor), **not a static analyser** (use PHPStan),
  not a formatter, not Rector, not a test runner, and **has no write side yet** — it does not edit files.
