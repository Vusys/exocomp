# exocomp reference

The detail behind `SKILL.md`: every command, how to read an answer, and what the tool declines. Each command also documents itself: `exocomp <command> --help`.

## Addressing

Names first. A name survives an edit and a byte offset does not, so every address is a name. The one
exception is the *file* half, which may be a path: the member half is still a name, so the address still
survives. Quote addresses, or the shell eats the backslashes.

```
App\Services\OrderService              a class, interface, trait or enum
App\Services\OrderService::place       a method (case-insensitive, as PHP is)
App\Models\Order::$table               a property, including a promoted one
App\Models\Order::CREATED_AT           a class constant or enum case
```

`locate`, `model`, `dispatches` and `facade` take a short name (`Order`) as well as a fully-qualified one.

**When the path is what you have, use it.** `show`, `outline`, `imports` and `members` all take a file path,
and `show` takes a member off one. There is no need to look a namespace up first, and no need for
`grep -n 'function x' -A 30 <file>`:

```
exocomp show 'app/Services/OrderService.php::place'     a member of whatever that file declares
exocomp show 'app/Services/OrderService.php'            the class it declares, if it declares one
exocomp show --at 'app/Services/OrderService.php:15'    the declaration covering that line
```

`--at` is for a position from a stack trace, a diff or a test failure. A position does not survive an edit,
which is why it is a flag rather than an address.

**This is the only way to read a Laravel migration.** A migration is `return new class extends Migration`:
an anonymous class, with no name for any command to take. Address it by its file:

```
exocomp show 'database/migrations/2024_01_01_000000_create_customers_table.php::up'
```

`show` takes several addresses at once, printed in the order asked for:

```
exocomp show 'App\Models\Order::customer' 'App\Models\Order::scopeActive' 'App\Models\Concerns\HasTenant::bootHasTenant'
```

Prefer this to a line range once you know where the members are. A range is correct only while every member
you want sits in one file, contiguously. It silently stops being correct when one of them is inherited.
Addresses have neither failure mode.

## Reading the answer

**Exit codes: `0` found, `1` not found, `2` error.** A command line that does not parse is an error, and the
message names the nearest real option or command. So `exocomp locate Foo >/dev/null && …` is a real
predicate. A `1` from `reaches` is an answer, not a failure: do not retry it. `query` exits `0` with
`0 matches` when nothing matches.

**Every record leads with `file:line:col`.** Cuttable, awkable, clickable.

**Every result carries a confidence, and it is the point of the tool:**

- `resolved`: the type is declared and checked. Safe to rewrite mechanically.
- `inferred`: reached through a step the code does not declare, such as a docblock, a container binding, an
  Eloquent chain or `config/auth.php`. A claim about the code, not a fact of it. The reason names the step.
- `textual`: the name matches, and the receiver could not be established.

**A guessed namespace is corrected rather than refused.** Guessing a namespace is the usual way a lookup
fails. When exactly one class has that short name, the question is answered with it and the substitution is
stated. Do not re-run, and do not pass `--scan`:

```console
$ exocomp show 'App\OrderService::place'
app/Services/OrderService.php:13-20  App\Services\OrderService::place
    public function place(Order $order): void
…
note: nothing is declared as "App\OrderService" — answered App\Services\OrderService instead
```

Read that note: the answer is about a *different class* from the one you named, and what you write next
must use the real name. Where two classes share the short name, the miss stands and both are named. Pick
one and ask again. So the namespace is optional when it is unambiguous: `exocomp show 'OrderService::place'`
is a legitimate address.

**Members get the same treatment.** A member the class inherits from a parent or a trait is answered from
where it is declared, with a note:

```
note: App\Models\Order does not declare "bootHasTenant" itself — answered App\Models\Concerns\HasTenant::bootHasTenant, from a trait it uses
```

A member the class does not have at all is looked for on related classes. If exactly one subclass,
implementor, used trait, or trait used by a subclass declares it, that is the answer, again with a note.
Otherwise the miss stands, exits `1`, and every declaration of the name is listed with how it relates to the
class you named. Unrelated classes are listed but never answered from. This applies to `show`,
`members <class>::<member>`, `callers-of` and `references-to`.

**What it will not stand behind is handed back, not dropped**, in a trailing block that names the command
to close the gap:

```console
$ exocomp members 'App\Models\Order'
…
── 1 not reached ──
extends Illuminate\Database\Eloquent\Model   not in the index — run: exocomp index --warm --vendor
```

Read that block. It is where "I could not see past here" lives. Treating an answer as complete coverage
without it is how an audit gets wrongly signed off. Its title says what kind of gap it is: `unresolved`
(`references-to`, `callers-of`, `calls-from`), `not reached` (`members`), `not followed` (`reaches`,
`graph`), `not found` (a multi-address `show`), `not read from the source` (`model`). `references-to`
also counts each confidence in the header: `39 references to App\Models\Invoice::send  (13 resolved, 18 inferred, 8 unresolved)`.
A single-address `show` has no block: it found the one thing you named, or exited `1`.

**Nothing is truncated silently.** A capped result says `… 22 more (use --limit)`, and `{"elided": 22}` in
JSON. Cap deliberately with `--limit N` or `--max-tokens N`, **not by piping to `head`**. The cut is the one
thing the tool guarantees to report, and `head` throws that away with the output it drops. It throws the
exit code away too: `exocomp locate Nope | head -1; echo $?` prints `0` after a miss, because a pipeline's
status is `head`'s.

**A ceiling of about 25,000 tokens applies by default when output is not a terminal**, and says so when it
applies. It stops one `members` on a god object or one `references-to` on a base class from filling your
context. `--max-tokens N` raises it, and `--max-tokens 0` removes it.

**Output shape does not depend on the TTY.** Only the default ceiling and colour do: output that is not a
terminal is plain text even when `FORCE_COLOR` is set (`--ansi` forces colour, and `NO_COLOR` is honoured).
Text is the default and the denser format. `--json` (JSONL, one record per line) is for `jq` and structural
joins. `--format=paths` prints deduplicated file paths, for piping into another tool.

## Language commands

| Command | Notes |
| --- | --- |
| `show <symbol>…` | several addresses at once; a file path is an address too; `--at file.php:line` for a position; `--without-bodies` for the skeleton. Exit `1` if any one is missing, and the missing are named |
| `outline <file\|class>` | every declaration, no bodies |
| `members <class>` | resolved through traits, parents and interfaces; each entry says where it came from. `--own` for declarations only. `--matching <regex>` filters by member name, and the header says how many it kept (`2 of 13 members matching`). `members <class>::<member>` narrows to that one member |
| `imports <file\|class>` | the `use` map, aliases resolved |
| `locate <name>…` | several names at once, since "which of these exists, and where" is usually the real question. `--kind=class\|interface\|trait\|enum\|function\|method\|property\|constant\|enum-case` |
| `subclasses` / `implementors` / `traits-users` `<name>` | transitive by default; `--direct` for one level |
| `overrides <symbol>` | every declaration of one member across the hierarchy, up and down |
| `references-to <symbol>` | also finds call sites written under another name (a scope, an accessor). `--resolved-only` emits `resolved` alone, the set a rename may rewrite. `--strings` also searches string literals (slower, and every hit is `textual`) |
| `callers-of <symbol>` / `calls-from <symbol>` | the call graph, one step, in either direction |
| `query <pattern>` | structural search; see below |
| `check [rules.json]` | saved rules against a baseline; exits `1` on anything new |
| `index` | what the symbol cache holds. `--warm` brings it up to date now, and `--vendor` includes dependencies |

`references-to` and `callers-of` stop opening files after 60 seconds (`--time-limit N`, `0` for none) and
answer with what they found. The header then says `incomplete, N files not searched` and a note repeats it,
so treat the answer as a lower bound. On a terminal they draw a progress line on stderr. Piped, they print
nothing extra.

**More than ten unresolved hits are grouped by reason**: one line per reason with its count, three examples
under each, and `--all-unresolved` to list every one. A common name such as `create` meets many
`Model::factory()->create()` calls, and listed one per line they bury the answer. In `--json` each group is
a `{"unresolved_reason": …, "count": N, "examples": 3}` record followed by its examples.

**Calls proved not to be on the target are excluded and counted**: `(…, 30 excluded)` in the header, with a
note giving the rule. There is one rule. The call is on a value Eloquent's own API returned from a model,
through framework methods only, and the target class has no ancestor outside the indexed source, so Eloquent
cannot return one. A callback, a `::class` argument, a value read out of the model, a scope, a macro, or an
application method of that name on a framework subclass leaves the call unresolved instead.

`show`, `outline` and `imports` take `--rev <SHA>` to answer about a past revision, and `--stdin` (with
`--as <path>`) to analyse a buffer you already hold. Corpus-wide commands refuse `--rev` rather than
ignore it.

If a name cannot be placed, `--scan` reads every file to find it. It is slow, so it is opt-in, and the
answer says when it declined to scan. You need it only for a class that shares a file with a
differently-named sibling.

`locate` needs no such flag. It reads the source roots before it returns a negative, so "nothing named X is
declared" means the files were read and X was not there. The message gives the size of the index behind it.
`--no-sync` skips that read: faster, but it can miss a name added since the last full pass.

**The source roots are what Composer declares, plus what the framework loads by scanning.** On a Laravel
project, `database/migrations/` and `routes/` are indexed alongside `app/`. So a migration's methods, and the
anonymous class that holds them, are reachable by every command that takes a file. Dependencies are not
indexed by default. When a block says a parent is `not in the index`, run `exocomp index --warm --vendor`.

## How receivers are typed

A call is only as good as the type of its receiver. Beyond declared types, these are followed. A step
through a docblock, a convention or a binding is `inferred`. A step through a native type is `resolved`.

| Receiver | Typed as | Confidence |
| --- | --- | --- |
| `app(X::class)`, `resolve(X::class)`, `app()->make(X::class)`, `$this->app->make(X::class)`, `App::make(X::class)` | `X`, or the class a provider binds `X` to | `resolved`, or `inferred` through a binding |
| `Invoice::find(1)`, `Invoice::query()->where(…)->latest()->first()`, `Invoice::factory()->create()`, `foreach (Invoice::all() as $invoice)`, `$invoices[0]` | the model | `inferred` |
| `$client->invoices()->create([])`, `$client->invoices->first()`, `foreach ($client->invoices as $invoice)`, `$client->latestInvoice` | the related model, read from the relation method's `return $this->hasMany(Invoice::class)` | `inferred` |
| `auth()->user()`, `Auth::user()`, a guard's `user()`, `$request->user()` | the user model `config/auth.php` names | `inferred` |
| `request()` and other helper functions in `vendor/` | a native return type, or a `@return` evaluated against the call (`request()` is the `Request`, `request('key')` is not) | `resolved` / `inferred` |
| `$this->guard()`, declared only in a vendor parent or trait | its native return type, or a single class in its `@return` | `inferred` |
| a method that returns `static` or `self` | the receiver's class, or the declaring class | `resolved` |
| `$x instanceof Invoice ? $x : null` | `Invoice`; a ternary or `??` whose branches agree, or whose other branch is `null`, takes that type | `resolved` |
| `$x` after `$x instanceof Invoice`: inside `if (…) {…}` or an `elseif`, on the right of `&&`, in a ternary's first branch, after `assert(…)`, and after a guard such as `if (! $x instanceof Invoice) { throw … }` that has no other branch and ends in `return`, `throw`, `continue`, `break` or `exit` | `Invoice`. Not in an `else`, not before the test in the same condition, and not after the block | `resolved` |
| an untyped property or method | the one class every assignment or `return` in its class names; failing that, its `@var` or `@return` | `inferred` |
| `$pages[0]`, `$chain['book']`, `foreach ($pages as $page)` on an array documented as `Page[]`, `list<Page>` or `array{book: Book}` | the element's class | `inferred` |

The reason on each hit names the step, for example `the signed-in user, the user provider model in config/auth.php, receiver is App\Models\Customer`.

A model's own local scopes and its factory's `static` state methods continue an Eloquent chain. Anything
outside a closed list of builder, factory and collection methods (a macro, `when()`, `tap()`, a callback)
stops it, and the hit is unresolved with that reason. A custom relation class keeps its own methods. Where
`config/auth.php` names more than one model, or none that can be read, the signed-in user stays unresolved.
A closure sees what PHP gives it: an arrow function sees the enclosing scope, a `function () use (…)` sees
what it `use`s, and the enclosing scope is restored after either.

## Laravel commands

These apply when `composer.json` requires `laravel/framework`, or `artisan` sits beside `app/`. Everything is
parsed out of ordinary PHP and SQL. The application is never booted, so these work on a branch you cannot run.

| Command | Notes |
| --- | --- |
| `model <class>` | table, attributes, attribute defaults, casts, fillable/guarded/hidden, accessors, relations and their target classes, global scopes, traits, and the `@property` block, each fact saying which class it came from. Each annotated property says what backs it. `--only=relation`, `--only=attributes` (defaults), `--only=annotated` and so on |
| `facade <Facade[::method]>` | what runs, through the accessor and the container, and where the method is declared |
| `schema [table]` | columns and indexes rebuilt from the squashed dump plus every migration since; omit the table to list them all |
| `config <key>` | where a dotted key is defined and everywhere it is read. `--defined` skips the corpus scan |
| `bindings [filter]` | container bindings, interface to concrete, contextual ones included |
| `routes [filter]` | URIs resolved through every enclosing group prefix. `--method=POST`, `--middleware` |
| `dispatches <class>` | where a job or event is dispatched, and what receives it |
| `morph-map [name]` | the alias map: the strings stored in your `*_type` columns, in either direction |

```console
$ exocomp model Ledger
App\Models\Ledger   app/Models/Ledger.php:16

attributes (1)
app/Models/Ledger.php:16  table                  ledgers   derived from the class name, but no migration creates a table of this name

attribute defaults (2)
app/Models/Ledger.php:20  status                 open
app/Models/Ledger.php:21  closed_at              null

── 2 not read from the source ──
app/Models/Ledger.php:22  grace_seconds                    the default is computed
app/Models/Ledger.php:25  entries                hasMany   a custom relation (HistoryMany extends Illuminate\Database\Eloquent\Relations\HasMany) built by historyMany(), which is given App\Models\Order — a factory this reader does not model, so the related class is not read
```

A default that is not a literal is declined as computed. A literal `null` is a value. A relation of a custom
class is declined with the core relation it extends, rather than left out.

**When the annotations and the schema disagree**, `model` says so. It holds four accounts of a model's
attributes (migrations, `$casts`, accessors, `@property`) and names the claims nothing else backs. A
generated block goes stale silently, and nothing else in a PHP toolchain tells you:

```console
$ exocomp model Order --only=annotated
App\Models\Order   app/Models/Order.php:25

annotated properties (4)
app/Models/Order.php:25  id                        @property on App\Models\Order — backed by a column
app/Models/Order.php:25  reference                 @property on App\Models\Order — backed by $fillable and a column
app/Models/Order.php:25  display_name              @property-read on App\Models\Order — backed by an accessor
app/Models/Order.php:25  legacy_code               @property on App\Models\Order — backed by nothing the model or its migrations declare
note: 1 of 4 annotated properties are accounted for by no column, cast, accessor or relation — legacy_code; the model was read to the end of its ancestry, so these are claims nothing backs
```

Ask for global scopes (`--only=scope`) before you write a query: nothing in a call site says they are there.

`schema` counts the indexes written on a column (`->unique()`, `->index()`, the primary key of `id()`) as
well as those declared separately. A foreign key is a constraint, not an index.

## Magic members

Members that exist at runtime and are declared nowhere the call site can see. Most of them *are* written
down, elsewhere, and the answer says which kind of evidence it found.

| What you write | What it resolves to | Confidence |
| --- | --- | --- |
| `Order::active()` | `Order::scopeActive()` | `resolved` |
| `$order->display_name` | `getDisplayNameAttribute()`, or `displayName(): Attribute` | `resolved` |
| `$order->customer` | `Order::customer()`, the relation method | `inferred` |
| `$repo->fetch()` with `@method` on the class | the annotated member | `inferred` |
| `$bag->send()` with `@mixin Notifier` | `Notifier::send()` | `inferred` |
| `$fwd->send()` where `__call` forwards to a typed property | the target's method | `inferred` |
| a macro | *where it was registered*, never a target | `inferred` |
| `$bag->anything()` with a bare `__call` | nothing, reported with the reason | `textual` |

**A convention reaches `resolved` because the transform is a total function of the name, and the target
was found and checked.** That holds only after the installed framework is confirmed to still implement the
convention. Where the framework cannot be read, it degrades to `inferred` and says so in the reason.
Eloquent's convention outranks a generated annotation: laravel-ide-helper's `@method static Builder|Order query()`
does not stop a chain at the bare builder.

**An annotation never reaches `resolved`.** It is a claim about the code, not a fact of it, and many are
generated. The reason names the file the claim came from, so "inferred through a generated helper" reads
differently from "inferred through a class in `app/`".

**The inverse direction is the one to remember before a rename.** Nothing writes `scopeActive` except the
declaration:

```console
$ exocomp references-to 'App\Models\Order::scopeActive'
3 references to App\Models\Order::scopeActive  (3 resolved, 0 inferred, 0 unresolved)
app/Models/Order.php:70:5            public function scopeActive($query) { return $query; }
app/Services/OrderQueries.php:21:16  Order::active()
app/Services/OrderQueries.php:27:16  $order->active()
```

**A call target is verified, not assumed.** A member absent from a class read to the end of its ancestry
is `textual` with the reason: it is a broken call or a magic one. On a class whose ancestry leaves the
indexed source (any framework subclass), an absence proves nothing, so the answer is unchanged.

## Reachability

`reaches <from> <to>` asks whether a call path exists. That is the shape of a tenant-scoping audit, a
cascade-deletion audit, or "does anything under this prefix write to that table".

```console
$ exocomp reaches 'App\Http\Controllers\OrderController::store' 'App\Models\Order::save'
App\Http\Controllers\OrderController::store reaches App\Models\Order::save in 3 steps; the weakest step is resolved
app/Http/Controllers/OrderController.php:35:9  method call  →  App\Services\OrderService::place
app/Services/OrderService.php:15:9             dispatch  →  App\Jobs\ProcessOrder::handle
app/Jobs/ProcessOrder.php:19:9                 method call  →  App\Models\Order::save
```

Every step carries its own confidence, and **the path is worth its weakest step**. A path through a
container binding is a different claim from one through five declared calls.

**A model write runs its observers.** `$order->delete()` continues into each observer method the write
fires, for observers registered with `#[ObservedBy]`, `Order::observe()` or an `$observers` array:

```console
$ exocomp reaches 'App\Services\ModelWrites::deleteOrder' 'App\Services\AuditLog::record'
App\Services\ModelWrites::deleteOrder reaches App\Services\AuditLog::record in 2 steps; the weakest step is resolved
app/Services/ModelWrites.php:23:9     observed  →  App\Observers\OrderObserver::deleted
app/Observers/OrderObserver.php:22:9  static call  →  App\Services\AuditLog::record
```

An event the write always fires (`deleted` from `delete()`) is as sure as the registration. One it fires
only for some models (`created` from `save()`) is `inferred`. A mass update such as
`Order::where(…)->update(…)` fires no model events, so it has no observer step.

**An authorization check runs its policy.** `$this->authorize('update', $order)`, `Gate::allows(…)`,
`Gate::authorize(…)` and `$user->can(…)` continue into the policy for the class they check: its `before()`
where it has one, then the method the ability names (`forceDelete` for `force-delete`):

```console
$ exocomp reaches 'App\Http\Controllers\AuthorizedActions::updateOrder' 'App\Policies\OrderPolicy::update'
App\Http\Controllers\AuthorizedActions::updateOrder reaches App\Policies\OrderPolicy::update in 1 step; the weakest step is resolved
app/Http/Controllers/AuthorizedActions.php:20:9  authorizes  →  App\Policies\OrderPolicy::update
```

A policy registered in `$policies`, with `Gate::policy()` or by `#[UsePolicy]` is as sure as the check. One
found only by Laravel's naming convention (`App\Policies\OrderPolicy`) is `inferred`. A check whose ability
is not a literal, whose subject's type is unknown, or that finds no policy method is listed as not followed,
with the reason. It is never a "no".

The destination is a shape, not an address:

```
App\Models\Order::save     that member of that class
App\Models\Order           anything on that class
::save                     that member on any class
App\Models\*::delete*      globs in either half
```

The start can be a route, so you can ask the question in the terms you thought it in. A pattern that
matches several endpoints sweeps them all in one call, with one verdict each:

```console
$ exocomp reaches 'route:POST /orders' 'App\Models\Order::save'
$ exocomp reaches 'route:*' 'App\Models\Order::save'
1 of 8 starting points reach App\Models\Order::save
routes/api.php:9:1   GET /api/orders             not reached
…
routes/web.php:18:1  POST /orders                reached in 3 steps
…
note: 1 matching route is answered by a closure rather than a controller, so there is no declaration to start from
```

**A negative is a claim about a whole graph, so read what it says about itself.** It exits `1`, with one of
these: the walk ran out of graph rather than budget and followed every edge it met (the strongest "no");
a `--depth` or `--max-nodes` budget cut it short; edges led into a dependency (`--through-vendor` follows
them); or some calls could not be resolved, each listed with its address and reason. Only the first means
"there is no path". The others mean "not within what I looked at": widen and ask again.

`graph <symbol>` is the same walk without a destination: everything within N steps outward, with the edges
it could not follow. Use it to orient in unfamiliar code. Both take `--depth` (6), `--max-nodes` (1500) and
`--through-vendor`.

## Structural search

`query` takes the code shape you want, written as PHP that has to parse. `$UPPERCASE` matches any node,
`_UPPERCASE` any name, `$_` any node without binding it, `...` any arguments, `()` any parameters, and `{}`
any body.

```console
$ exocomp query '$R->send()' --in 'App\Services\**'
$ exocomp query 'dispatch(...)' --arg-is-not-literal 0
$ exocomp query 'function _M() {}' --annotated @deprecated --visibility public
```

Predicates: `--in` / `--not-in`, `--annotated`, `--has-attribute`, `--arg-is-literal` /
`--arg-is-not-literal` (0-based), `--param-count`, `--visibility`, `--returns`, `--uses-trait`, `--extends`,
`--implements`, `--receiver-type` / `--receiver-implements` / `--receiver-extends`. `--capture NAME` prints
one binding only. All are syntactic except the three receiver predicates, which need inference. A receiver
whose type cannot be established does not match, so a scoped audit stays scoped.

`check` turns a sweep into a guard: rules as JSON (every `query` predicate is a key), a baseline of what
already fails, and exit `1` on anything new. Baseline entries are keyed by rule, file, enclosing declaration
and matched code, never by line number.

## Recipes

```bash
# Before a rename: what may be rewritten, and what must be judged by hand.
exocomp references-to 'App\Models\Order::save' --resolved-only
exocomp references-to 'App\Models\Order::save'          # then read the inferred and unresolved groups

# Renaming a scope or an accessor: the call sites write a different name entirely.
exocomp references-to 'App\Models\Order::scopeActive'   # finds every `->active()` too

# Which of a model's annotated properties nothing backs: a stale generated block.
exocomp model Order --only=annotated

# The real API of a class, without opening its parents.
exocomp members 'App\Models\Order'

# Only the part you care about. Use --matching rather than piping to grep: it says how many
# members it kept out of how many exist, so a narrow pattern cannot read as a small class.
exocomp members 'App\Models\Order' --matching 'tenant|active'

# Then read the ones that matter, by name, not by line range. `members` says which class each
# came from, and that is the class to name here; they may live in different files.
exocomp show 'App\Models\Order::scopeActive' 'App\Models\Concerns\HasTenant::bootHasTenant'

# Which endpoints touch a model at all.
exocomp reaches 'route:*' 'App\Models\Order'

# What a facade call runs, before you change it.
exocomp facade 'Reporting::build'

# A line number from a stack trace or a failing test, turned into the declaration around it.
exocomp show --at 'app/Services/OrderService.php:15'

# Every file involved in a change, for a follow-up pass.
exocomp references-to 'App\Models\Order' --format=paths | xargs …

# Structural join: which deprecated methods still have callers.
exocomp query 'function _M() {}' --annotated @deprecated --json | jq -r .fqn |
  while read -r m; do exocomp callers-of "$m" --json; done
```

## What it declines: do not ask it these

It says so in the answer rather than guess, and the honest gap is the feature. Do not paper over one with
a grep and present the result as complete.

- **A `__call` that computes its target**, one that parses a prefix or reads out of an array. A
  single-statement forwarder to a typed property is followed one step. Anything more involved is declined,
  because following it would mean evaluating the method. Also **string callables, `call_user_func` with a
  computed callable, `$obj->$method()`, variable class names, `extract()`, `class_alias()`**.
- **Eloquent's builder methods as targets.** A chain is typed through them to the model it hands back, but
  `Order::where(...)->update(...)` is not resolved to the framework's `update()`. A chain through a macro,
  `when()`, `tap()` or a callback stops, and says why. A reference search can rule such a call *out* (see the
  exclusion rule), but never in.
- **Middleware.** Its registration sites are readable, but its edges are not modelled yet. Nor is a model's
  own `static::created(fn …)` in `booted()`, a `can:` route middleware, or a form request's `authorize()`.
- **Calls inside a closure passed as an argument** in `reaches`, `calls-from` and `graph`: a closure's
  parameters are not typed there, so `->each(fn (Song $song) => $song->delete())` stops with the reason.
- **Dependencies, by default.** An edge *into* `vendor/` is recorded and checked against the destination, so
  a path that ends at a framework method is found. Walking on is `--through-vendor`.
- **A widely implemented interface** fans out rather than leads somewhere. The walk follows twelve
  implementations and says when it stopped.
- **`morphTo`** names no class. What it points at is decided by the morph map, not the file.
- **A binding whose concrete side is a closure** is followed exactly one step (a closure that returns one
  `new X` is that X). Anything more involved is declined.
- **A relation built by a custom factory** (`$this->historyMany(Order::class)`) is reported with the core
  relation it extends, but its related class is not read.
- **Schema** is the *source's* account, never the database's. Every row is `inferred`, and a table whose
  history includes raw SQL is reported as incomplete rather than quietly short a column.
- It is **not an LSP** (no completion, no hover; `show --at file:line` resolves a position, but nothing takes
  a live cursor), **not a static analyser** (use PHPStan), not a formatter, not Rector, not a test runner,
  and **has no write side yet**: it does not edit files.
