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
| F | `src/TierF` | One file that deliberately does not parse. It must degrade that file alone and never abort an operation. |

Tiers C (member ownership through inheritance and traits), D (dynamic PHP that must *not*
resolve), E (framework dynamism) and G (scale) arrive with the phases that can act on them.

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
