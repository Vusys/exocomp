---
name: exocomp
description: "Use instead of grep and sed on PHP code, at the moment you are about to type one of these. `grep -n 'function foo'` then `sed -n X,Yp` → `exocomp show 'App\\Foo::foo'`, or `exocomp show 'app/Foo.php::foo'` when you have the path. `grep -rl 'class Foo'` → `exocomp show Foo` or `exocomp locate Foo`. Grepping a name to find callers or usages → `exocomp callers-of 'App\\Foo::foo'` or `exocomp references-to`, which drop same-named methods on unrelated classes, find calls through subclasses, and follow Eloquent chains, relations and `auth()->user()` to the model. Grepping guessed method names → `exocomp members 'App\\Foo'`, parents and traits included. One call returns the exact declaration, with no guessed line range. Also answers Laravel questions grep cannot: a model's table, casts, defaults and relations, a table's columns and indexes from migrations, routes, container bindings, and what a facade runs. Not for strings, config values, comments or non-PHP files: grep those."
allowed-tools: Bash, Read
---

# exocomp

Run `exocomp <command> <name>` from anywhere in the project (`vendor/bin/exocomp` if it is not on `PATH`). There is nothing to build, and it never boots the application.

## When you are about to grep PHP, run this instead

| About to type | Run instead | Why |
| --- | --- | --- |
| `grep -n 'function place' app/Services/OrderService.php`, then `sed -n 10,25p` | `exocomp show 'app/Services/OrderService.php::place'` | The exact span with its docblock, in one call. No guessed range that stops one line short. |
| `grep -rn 'function place' app/` | `exocomp show 'App\Services\OrderService::place'`, or `show 'OrderService::place'` | A guessed or omitted namespace is corrected, and the note names the real class. |
| `f=$(grep -rl 'class ProcessOrder' app); sed -n 1,150p $f` | `exocomp show ProcessOrder` (add `--without-bodies` for signatures only) | One call instead of two, and never cut off. |
| `grep -rn 'send(' app` to find who calls it | `exocomp callers-of 'App\Models\Invoice::send'` | Ignores `send()` on unrelated classes. Finds calls through subclasses, a scope called as `active()`, and receivers grep cannot type: `Invoice::find(1)->send()`, `$client->invoices()->first()->send()`, `auth()->user()->orders()`. |
| `grep -rn 'ProcessOrder' app` to find who uses a class | `exocomp references-to 'App\Jobs\ProcessOrder'`, or `dispatches ProcessOrder` for a job or event | Imports, aliases and type hints are resolved. `dispatches` also says what handles it. |
| `grep -n 'function \(sync\|import\|map\)' Foo.php` to guess what exists | `exocomp members 'App\Foo' --matching 'sync\|import\|map'` | Includes what the class gets from parents and traits, which is not in the file. |
| Several of the above at once | `exocomp show 'App\A::x' 'App\B::y'` or `exocomp locate A B C` | One call answers for every name. |

Laravel questions grep cannot answer: `model Order` (table, casts, defaults, relations and their targets, accessors, and what backs each `@property`), `schema shop_orders` (columns and indexes rebuilt from migrations), `routes`, `bindings`, `facade 'Reporting::build'`, and `config services.stripe.key`.

## Where it does not help: use grep

- String literals, log messages, config values, comments, Blade, JS, YAML: anything that is not a PHP declaration or call.
- Exact text you want verbatim, such as a SQL fragment or an error message.
- Dynamic calls the tool reports as unresolved (`$obj->$method()`, string callables, a chain through a macro or a callback). It lists them and says why. Read those yourself rather than trusting either tool.

## Reading the answer

- **Confidence.** Every hit is `resolved` (declared and checked), `inferred` (through a step the code does not declare: a docblock, a binding, an Eloquent chain or relation, `config/auth.php`) or `textual` (the name matches but the receiver is unknown). Only `resolved` is safe to rewrite mechanically.
- **What it could not see.** `references-to` counts each confidence in the header, such as `(4 resolved, 2 inferred, 1 unresolved, 30 excluded)`. The rest goes in a trailing block, such as `── 3 unresolved ──`, `── 1 not reached ──` or `── 2 not followed ──`. More than ten unresolved hits are grouped by reason, and `--all-unresolved` lists every one. An answer is complete coverage only once you have read that block.
- **Exit codes.** `0` found, `1` not found, `2` error, `3` not found but incomplete. A `1` is an answer, so do not retry it with grep. A `3` is a "none" with gaps: read the trailing block for what could not be checked.
- **Do not pipe to `head`.** Piped output is already capped at about 25,000 tokens, and every cut is reported (`… 22 more (use --limit)`). `| head` hides the cut and replaces exocomp's exit code with its own. To cap output, use `--limit N` or `--max-tokens N`.

## More

Other commands: `outline <file>`, `imports`, `subclasses`, `implementors`, `traits-users`, `overrides`, `calls-from`, `reaches <from> <to>` (whether a call path exists, including from `route:*`), `graph` (callees within N steps), `query '<php shape>'` (structural search), `check`, and `index`. Each one documents itself in `exocomp <command> --help`.

The full reference covers addressing, every command, how receivers are typed, magic members, reachability, structural search, recipes and what the tool declines. It is `reference.md` beside this file in the exocomp repository (`skills/exocomp/reference.md`).
