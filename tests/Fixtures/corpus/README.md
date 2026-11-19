# The fixture corpus

A miniature project that exists only to be analysed. Its `composer.json` is never installed;
it is there so that PSR-4 resolution has something real to work against, and so `--project`
has a root to discover.

Nothing here is loaded by the test suite. These files are *data*: their bytes, including the
awkward ones, are what is under test.

| Tier | Directory | What it is for |
| --- | --- | --- |
| A | `src/TierA` | The baseline that must resolve, 100% — including PHP 8.4 property hooks and asymmetric visibility, which are the standing regression guard on version currency. |
| B | `src/TierB` | Naming and resolution traps: aliases, group `use`, short names shared across namespaces, PHP's case-sensitivity asymmetry, brace namespaces, conditional declarations. The tier where a textual search is *wrong* rather than merely noisy. |
| D | `src/TierD` | Reference resolution: which call sites are the member asked about, and how sure the tool may be. One `Notifier` hierarchy plus an impostor with a same-named `send()`, called through declared types, a subclass, `new`, a docblock, a shouted method name, no type at all, and a variable method name. Typing from older code (`LegacyCallers`), from documented arrays (`ArrayCallers`), and through magic members (`Magic`, `MagicCallers`): `Bag` and `AnnotatedBag` have identical bodies, and only the annotated one may come back `inferred`. The tier where getting it wrong breaks a rename at runtime. |
| F | `src/TierF` | One file that deliberately does not parse. It must degrade that file alone and never abort an operation. |

Tiers C (member ownership through inheritance and traits), E (framework dynamism) and G
(scale) are not here. Framework dynamism is covered by the Laravel fixture in
`tests/Fixtures/laravel` instead.

`tests/Fixtures/installed` is a second, much smaller corpus. This one has a dumped
`vendor/composer/autoload_classmap.php`, because the locator behaves differently depending on
whether Composer has been run: with a classmap it declines the expensive content scan and says
so, without one it has nothing else left to try and scans unasked.

## Do not tidy these files

Pint, Rector and PHPStan are all configured to skip `tests/Fixtures`. If that ever lapses, a
formatter will "fix" a naming trap or a refactorer will modernise a construct away, and the
fixture will keep passing while no longer testing anything.
`tests/Unit/Analysis/FixtureIntegrityTest.php` asserts the constructs are still present as
bytes, so the lapse fails the build rather than going unnoticed.
