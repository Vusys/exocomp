---
name: exocomp
description: "Use instead of grep and sed on PHP code, at the moment you are about to type one of these. `grep -n 'function foo'` then `sed -n X,Yp` → `exocomp show 'App\\Foo::foo'`, or `exocomp show 'app/Foo.php::foo'` when you have the path. `grep -rl 'class Foo'` → `exocomp show Foo` or `exocomp locate Foo`. Grepping a method or class name to find callers or usages → `exocomp callers-of 'App\\Foo::foo'` or `exocomp references-to`, which drop same-named methods on unrelated classes and find calls through subclasses. Grepping guessed method names → `exocomp members 'App\\Foo'`, parents and traits included. One call returns the exact declaration, with no guessed line range. Also answers Laravel questions grep cannot: a model's table, casts and relations, a table's columns from migrations, routes, container bindings, and what a facade runs. Not for strings, config values, comments or non-PHP files: grep those."
allowed-tools: Bash, Read
---

# exocomp

Run `exocomp <command> <name>` from anywhere in the project (`vendor/bin/exocomp` if it is not on `PATH`). There is nothing to build, and it never boots the application.

## When you are about to grep PHP, run this instead

| About to type | Run instead | Why |
| --- | --- | --- |
| `grep -n 'function place' app/Services/OrderService.php`, then `sed -n 40,90p` | `exocomp show 'app/Services/OrderService.php::place'` | The exact span with its docblock, in one call. No guessed range that stops one line short. |
| `grep -rn 'function place' app/` | `exocomp show 'App\Services\OrderService::place'`, or `show 'OrderService::place'` | A guessed or omitted namespace is corrected, and the note names the real class. |
| `f=$(grep -rl 'class ProcessOrder' app); sed -n 1,150p $f` | `exocomp show ProcessOrder` (add `--without-bodies` for signatures only) | One call instead of two, and never cut off. |
| `grep -rn 'place(' app` to find who calls it | `exocomp callers-of 'App\Services\OrderService::place'` | Ignores `place()` on unrelated classes, finds calls through subclasses, and finds a scope called as `active()`. |
| `grep -rn 'ProcessOrder' app` to find who uses a class | `exocomp references-to 'App\Jobs\ProcessOrder'`, or `dispatches ProcessOrder` for a job or event | Imports, aliases and type hints are resolved. `dispatches` also says what handles it. |
| `grep -n 'function \(sync\|import\|map\)' Foo.php` to guess what exists | `exocomp members 'App\Foo' --matching 'sync\|import\|map'` | Includes what the class gets from parents and traits, which is not in the file. |
| Several of the above at once | `exocomp show 'App\A::x' 'App\B::y'` or `exocomp locate A B C` | One call answers for every name. |

Laravel questions grep cannot answer: `model Order` (table, casts, relations and their targets, accessors), `schema orders` (columns rebuilt from migrations), `routes`, `bindings`, `facade 'Storage::disk'`, and `config app.name`.

## Where it does not help: use grep

- String literals, log messages, config values, comments, Blade, JS, YAML: anything that is not a PHP declaration or call.
- Exact text you want verbatim, such as a SQL fragment or an error message.
- Dynamic calls the tool reports as unresolved (`$obj->$method()`, string callables). It lists them and says why. Read those yourself rather than trusting either tool.

## Reading the answer

- **Confidence.** Every hit is `resolved` (declared and checked), `inferred` (from a docblock, a binding, or a base-class receiver) or `textual` (the name matches but the receiver is unknown). Only `resolved` is safe to rewrite mechanically.
- **What it could not see.** `references-to` and `callers-of` give counts in the header, such as `(4 resolved, 2 inferred, 1 unresolved, 30 excluded)`, and summarise a long unresolved list by reason (`--all-unresolved` lists every hit). `members`, `reaches`, `graph` and multi-address `show` end with a `── N not listed ──` block. An answer is complete coverage only once you have read that part.
- **Exit codes.** `0` found, `1` not found, `2` error. A `1` is an answer, so do not retry it with grep.
- **Do not pipe to `head`.** Piped output is already capped at about 25,000 tokens, and every cut is reported (`… 47 more (use --limit)`). `| head` hides the cut and replaces exocomp's exit code with its own. To cap output, use `--limit N` or `--max-tokens N`.

## More

Other commands: `outline <file>`, `imports`, `subclasses`, `implementors`, `traits-users`, `overrides`, `calls-from`, `reaches <from> <to>` (whether a call path exists, including from `route:*`), `graph` (callees within N steps), `query '<php shape>'` (structural search), and `check`. Each one documents itself in `exocomp <command> --help`.

The full reference covers addressing, every command, magic members, reachability, structural search, recipes and what the tool declines. It is `reference.md` beside this file in the exocomp repository (`skills/exocomp/reference.md`).
