# Phase 0 against the benchmark corpus

REPORT.md §9 proposes a cheap acceptance test: 60 genuine PHP search commands lifted unedited
from real transcripts, with one bar per command — **answer it in one call, in fewer output
tokens, with no truncation.** Anything that cannot be answered that way is either a missing
capability or a deliberate anti-scope decision, and either finding is useful.

This is that measurement, taken against the Phase 0 build.

## Method, and its limit

Each of the 60 commands was classified by the intent visible in it, and matched against what
Phase 0 can actually answer. **The commands were not executed against the codebase they came
from** — it is not present in this checkout, and the corpus references its paths and
identifiers directly. So this is a reading of the 60 commands, not a run of them. It is good
enough to answer "is the Phase 0 slice the right slice", which is what §9 asks; it is not a
measurement of output tokens, and no token claim is made here.

The corpus itself is deliberately **not committed to this repository**: it carries real paths
and identifiers from a private codebase, exactly as REPORT.md §9 warns.

## Result

| Bucket | Commands | Share |
| --- | ---: | ---: |
| Answered now, in one call | 9 | 15% |
| Improved now, but needs a follow-up call | 9 | 15% |
| Needs Phase 1 (index, `locate`, inherited members) | 8 | 13% |
| Needs Phase 2 (structural query) | 16 | 27% |
| Needs Phase 3 (references, inference) | 3 | 5% |
| Needs Phase 4 (Laravel profile) | 6 | 10% |
| Anti-scope — stays with grep | 9 | 15% |

### What "answered now" looks like

The clearest case in the corpus is a single command that composes `grep -n`, a line-range
extraction and a `sed` address range to print one method body. That is `show
'…\UpdateController::preserveHiddenConfiguration'`: one call, an exact span, and no guess at
where the method ends. Several others are `outline` of a test file — the recurring
`grep -n 'public function\|#\[Test\]\|…'` idiom — and two are `show` of a vendor framework
method, which resolves through Composer's classmap with no index.

### What the buckets say

**Phase 2 is the largest single bucket, at 27%.** Sixteen of the sixty are structural questions
expressed as text filters: call sites whose first argument is not a literal, declarations
carrying a particular attribute, references minus a specific file. This is consistent with the
2,170 multi-stage pipes the original audit counted, and it says the structural query language
is where the next large tranche of value sits — not more read commands.

**Anti-scope is 15%, and that is a healthy number rather than a gap.** Those nine are keyword
hunts through arbitrary prose and string literals, or searches spanning `.vue` and `.js` files.
Grep is the right tool for them and always will be; a PHP-aware engine has nothing to add.

**Phase 0's own share is smaller than the original estimate.** REPORT.md §3.2 projected that
the three Tier 1 commands would target "roughly 3,000 of the 3,577 measured searches". Against
this 60-command sample the fully-answered share is 15%, with another 15% improved. The two
numbers are not in direct conflict — the 3,000 figure counts searches whose *intent* is a
declaration lookup, while this sample is weighted towards the ugly composed pipelines that were
selected for being representative of the hard cases. But it is the more pessimistic reading and
it should be recorded as such rather than smoothed over.

## Changes this measurement produced

**Dense property signatures now carry scalar defaults.** Four of the sixty commands search for
`protected $table`, `protected $connection`, `protected $casts` or a lookback window, and in
every one of them the *value* is the answer — a signature showing `protected string $table`
and hiding `= 'users'` sends the reader back to the file for one token's worth of information.
Scalars, `true`/`false`/`null` and constant references are now rendered inline; arrays and
expressions are not, because they would blow the line budget that makes `outline` worth using,
and they remain `show`'s job.

No other Phase 0 capability gap was found. The remaining 85% is either scheduled work or
deliberately out of scope.

## Re-running this

Point the corpus at a checkout it matches, then, per command, compare the exocomp equivalent
for call count and output size. The bar stays the one §9 sets: one call, fewer tokens, no
truncation.
