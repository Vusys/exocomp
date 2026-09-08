# Golden fixtures

One file per whole-command snapshot, in php-parser's three-section format:

```
A short name for the case
-----
members Fixtures\TierA\Baseline
-----
<expected stdout, verbatim>
```

The command line is run against `tests/Fixtures/corpus` with `--project` pointing at it, so
every path in the expected output is relative to the corpus root and stable across checkouts.
Any argument naming a file that exists under the corpus is resolved to an absolute path before
the run; everything else is passed through untouched.

A non-zero exit is declared by prefixing the command with `[exit=N]`. Only stdout is compared —
stderr is asserted separately, in `tests/Unit/Console/ExitCodesTest.php`, because keeping
diagnostics off stdout is itself part of the contract.

## What belongs here, and what does not

Golden files answer "did the interface stay stable" — column alignment, header shape, the
truncation notice, the trailing block. They are the right tool for output-contract drift and
the wrong tool for semantics: an assertion about *which* declaration was resolved belongs in a
unit test, where a failure says what was wrong rather than showing two blocks of text.

Do not regenerate a failing golden file without reading the diff. A snapshot that gets
refreshed on every failure asserts nothing.
