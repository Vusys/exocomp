# exocomp

Agent-first PHP code intelligence. Ask about a symbol by name and get the whole answer back,
using only as many tokens as the answer needs.

```console
$ exocomp show 'App\Services\OrderService::place'
app/Services/OrderService.php:13-20  App\Services\OrderService::place
    public function place(Order $order): void
    {
        dispatch(new ProcessOrder($order));

        ProcessOrder::dispatch($order);

        event(new OrderPlaced($order));
    }
```

## Why

Agents working in PHP reach for `grep` and `sed`, even when a better tool is installed and
documented. They do this because `grep` is fast, composes in a shell and prints dense output.
That habit has a cost:

- **Find, then read.** A `grep -n` is followed by a `sed -n 'N,Mp'` at a guessed range. If the
  guess is too short, the method body is cut off, and a cut-off body reads as a complete one.
- **Guessing names.** Several likely method names are OR'd together to find out which one
  exists.
- **Text filters for structural questions.** Long pipelines approximate questions about code
  shape, and they cannot tell a call from a comment.
- **Framework questions.** Many of the questions are about facades, config keys, models and
  migrations. A language-only tool can tell you a relation method exists. It cannot tell you
  which class the relation points at.

exocomp answers those questions in one call. It never boots your application.

## Install

```console
composer require --dev vusys/exocomp
```

You can also build a single-file PHAR with [Box](https://github.com/box-project/box):
`box compile` writes `builds/exocomp.phar`, which needs only PHP 8.4.

## Usage

Address symbols by name:

```
App\Services\OrderService              a class, interface, trait or enum
App\Services\OrderService::handle      a method
App\Models\Order::$table               a property
App\Models\Order::CREATED_AT           a class constant or enum case
```

| Command | Answers |
| --- | --- |
| `show`, `outline`, `members`, `imports`, `locate` | Source, declarations and a class's whole API |
| `subclasses`, `implementors`, `traits-users`, `overrides` | The inheritance graph |
| `references-to`, `callers-of`, `calls-from`, `graph` | References and the call graph |
| `reaches <from> <to>` | Whether a call path exists |
| `query <pattern>`, `check [rules.json]` | Structural search, and rules that guard against new matches |
| `model`, `facade`, `schema`, `config`, `bindings`, `routes`, `dispatches`, `morph-map` | Laravel answers, read from the source |

Run `exocomp list` for every command and `exocomp help <command>` for its options.

Every command keeps to the same output contract:

- Text by default, or `--json`. Every record starts with `file:line:col`.
- Nothing is cut off without notice. A capped result says how many more there are.
- Every result has a confidence: `resolved`, `inferred` or `textual`. Anything the tool cannot
  vouch for goes in a trailing block, with the reason.
- Exit codes: `0` found, `1` not found, `2` error.

## What it can do

The examples below come from a small, fictional Laravel app.

**Find a scope from the method name it is called by.**

```console
$ exocomp references-to 'App\Models\Order::scopeActive'
3 references to App\Models\Order::scopeActive  (3 resolved, 0 inferred, 0 unresolved)
app/Models/Order.php:70:5            public function scopeActive($query) { return $query; }
app/Services/OrderQueries.php:21:16  Order::active()
app/Services/OrderQueries.php:27:16  $order->active()
```

**Know which model the signed-in user is.**

```console
$ exocomp references-to 'App\Models\Customer::orders'
4 references to App\Models\Customer::orders  (1 resolved, 3 inferred, 0 unresolved)
app/Models/Customer.php:14:5           public function orders(): HasMany { return $this->hasMany(Order::class); }

inferred (3)
app/Services/SignedInCallers.php:17:9  auth()->user()->orders()
app/Services/SignedInCallers.php:22:9  Auth::user()->orders()
app/Services/SignedInCallers.php:27:9  $request->user()->orders()
```

**See a model whole.**

```console
$ exocomp model 'App\Models\Order'
App\Models\Order   app/Models/Order.php:25

attributes (2)
app/Models/Order.php:29  table                  shop_orders
app/Models/Order.php:31  connection             shop

relations (3)
app/Models/Order.php:42  customer               belongsTo  App\Models\Customer
app/Models/Order.php:47  lines                  hasMany  App\Models\OrderLine
app/Models/Order.php:52  comments               morphMany  App\Models\Comment

accessors (2)
app/Models/Order.php:78  display_name           getDisplayNameAttribute()   read as an attribute by the framework's naming convention
app/Models/Order.php:87  short_code             shortCode()   read as an attribute — declared with an Attribute return type, not by its name
…
```

**Follow a route to a write, through a queued job.**

```console
$ exocomp reaches 'route:POST /orders' 'App\Models\Order::save'
POST /orders (App\Http\Controllers\OrderController::store) reaches App\Models\Order::save in 3 steps; the weakest step is resolved
app/Http/Controllers/OrderController.php:35:9  method call  →  App\Services\OrderService::place
app/Services/OrderService.php:15:9             dispatch  →  App\Jobs\ProcessOrder::handle
app/Jobs/ProcessOrder.php:19:9                 method call  →  App\Models\Order::save
```

**Sweep every route at once.**

```console
$ exocomp reaches 'route:*' 'App\Models\Order::save'
1 of 8 starting points reach App\Models\Order::save
routes/api.php:9:1   GET /api/orders             not reached
routes/web.php:9:1   GET /admin/orders           not reached
routes/web.php:18:1  POST /orders                reached in 3 steps
…
```

**Say how much a "no" is worth.**

```console
$ exocomp reaches 'App\Services\ReportBuilder::build' 'App\Models\Order::save'
no path from App\Services\ReportBuilder::build to App\Models\Order::save; 1 declaration was walked
note: the walk ran out of graph rather than out of budget, and every edge it met was followed — so within the edges this tool models, there is no path
```

**Resolve a facade to the class behind it.**

```console
$ exocomp facade 'App\Facades\Reporting::build'
App\Facades\Reporting resolves through the container key "reporting"
app/Facades/Reporting.php:13  App\Facades\Reporting  →  App\Services\ReportBuilder

method (1)
app/Services/ReportBuilder.php:11  App\Services\ReportBuilder::build()
note: bound to "reporting" in App\Providers\AppServiceProvider
```

**Find where a job is dispatched and what handles it.**

```console
$ exocomp dispatches 'App\Jobs\ProcessOrder'
App\Jobs\ProcessOrder — dispatched in 3 places, handled in 1

dispatched (3)
app/Services/OrderService.php:15      dispatch()
app/Services/OrderService.php:17      ProcessOrder::dispatch()
app/Services/ReportDispatcher.php:48  dispatch()

handled by (1)
app/Jobs/ProcessOrder.php:17  App\Jobs\ProcessOrder::handle                      the job handles itself
```

**Search by code shape, not text.**

```console
$ exocomp query '$X->save()'
2 matches for $X->save()  (2 files scanned)
app/Jobs/ProcessOrder.php:19:9         X=$this->order
app/Listeners/NotifyCustomer.php:16:9  X=$event->order
```

## What it does not do

It is not an LSP, a static analyser, a formatter or a refactoring tool. It does not boot your
application, run migrations or connect to your database.

## Development

```console
composer test        # PHPUnit
composer check       # Pint --test, Rector --dry-run, PHPStan level 9
composer sweep       # exocomp's own rules, against its own baseline
composer infection   # mutation testing
```

`tests/Fixtures` is excluded from Pint, Rector and PHPStan on purpose: much of it is deliberately
pathological code. See `tests/Fixtures/corpus/README.md`.
