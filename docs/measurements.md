# Measured against a real corpus

REPORT.md §9 proposes a cheap acceptance test: 60 genuine PHP search commands lifted unedited
from real transcripts, with one bar per command — **answer it in one call, in fewer output
tokens, with no truncation.** Anything that cannot be answered that way is either a missing
capability or a deliberate anti-scope decision, and either finding is useful.

This is that measurement, run against the private Laravel monolith the commands came from:
6,920 application files (949,910 lines, 33.7 MB) and 30,910 vendor files (172.5 MB). It has been
kept up to date as later phases landed, because a measurement taken once and never repeated is
just a claim with a date on it.

Neither the corpus of commands nor the codebase is committed here, and nothing in this
repository references either — both carry real paths and identifiers from a private codebase, exactly
as REPORT.md §9 warns. What follows is aggregate only.

## Does it parse the real thing?

The first question is not about tokens. An engine that mis-parses is worse than no engine, and
the whole case for building this one rather than reusing an existing engine was that the
obvious alternative could not see PHP 8.4 syntax.

| Corpus | Files | Size | Declarations | Members | **Parse failures** |
| --- | ---: | ---: | ---: | ---: | ---: |
| Application, tests, database | 6,920 | 33.7 MB | 6,535 | 47,851 | **0** |
| Vendor | 30,910 | 172.5 MB | 29,106 | 213,794 | **0** |

Throughput is roughly 460 files/second single-threaded, at 24 MB peak memory over the
application and 220 MB over vendor.

## Does it find what grep finds?

REPORT.md §6.3 sets the rule: **every textual hit grep finds must be either classified or
explained.** The full version of that gate belongs to Phase 3, which has to account for
*references*; what can be checked now is *declarations*.

A deliberately generous regex for a named function or method declaration was run over all 4,516
application files and compared against what the tool extracts:

| | |
| --- | ---: |
| grep declarations | 18,587 |
| exocomp methods | 18,568 |
| files disagreeing | 7 (0.16%) |
| net difference | −19 (−0.10%) |

All nineteen were explained, and **none was a genuine miss**:

- **15 are methods of anonymous classes** (`return new class { … }`). The collector does not
  descend into expressions, and an anonymous class has no name for a symbol address to reach.
- **4 are commented-out code** inside `/* … */` blocks. Here grep is wrong and the parser is
  right.

The first group prompted a change (below). The second is the tool doing its job.

## Does the JSON contract hold on real source?

Every declaration in both corpora was rendered as JSONL and decoded again — 33,087 records over
application code and 242,900 over vendor.

Application code was clean. Vendor produced **eleven records that encoded to an empty JSON
object**, which is the worst available outcome: a consumer reading the stream to completion
cannot tell an empty record from a record that was dropped.

The cause was two faults meeting. A dense signature caps a long default value at a byte count,
and a byte cut through non-Latin text splits a multi-byte character in half, leaving a dangling
continuation byte; the record is then not valid UTF-8. `json_encode()` returns `false` on that,
and the encoder fell back to `{}`. Both are fixed: values are cut on character boundaries, and
an unencodable record now declares the substitution instead of vanishing. The sweep is clean.

This is the single most valuable thing the exercise found, and it is worth noting how: not by a
fixture, and not by any test that could plausibly have been imagined in advance, but by pushing
a few hundred thousand real declarations through the renderer and checking the output was still
there.

## Is the tool's name-to-file resolution well founded?

Phase 0 resolves a name with no index, so how a real project is actually laid out decides
whether that works. Two measurements, both surprising enough to change the code:

- **Application code is 99.93% PSR-4 conformant** — 3 class-likes out of 4,462 are not at the
  path their name implies, and all 3 share a file with a differently-named sibling. Test code
  is markedly worse at 94.9%, and a large part of that is test classes whose namespace does not
  begin with the mapped prefix at all, so PSR-4 arithmetic cannot place them; a filename match
  can, and does.
- **The dumped classmap contained zero classes in the project's own namespaces.** Composer only
  writes those under `dump-autoload -o`, which this project does not use, so the classmap holds
  vendor classes exclusively.

## Time to first answer

Everything is a cold process — Phase 0 has no index and no daemon.

| | |
| --- | ---: |
| `php -n -r ''` (bare interpreter) | 53 ms |
| exocomp, application class | 130 ms |
| exocomp, vendor class via the classmap | 128 ms |
| exocomp, class placed only by filename | 122 ms |
| exocomp, name that cannot be placed | 235 ms |
| exocomp, same with `--scan` | 1,341 ms |
| `grep -rn` over application code | 139 ms |
| `grep -rn` over application code and vendor | 304 ms |

**The tool's own work is about 75 ms** — faster than the recursive grep it replaces. Everything
else is the PHP interpreter starting. On the machine this was measured on, the default
configuration loads 69 extensions and `php -r ''` alone costs 172 ms, which is more than the
work the tool does.

That is worth stating plainly because it points at the fix. Time-to-first-answer is the
existential risk (REPORT.md §8), and the dominant term in it is not the engine — it is process
startup. The persistent daemon in §4.1 is therefore not a performance nicety for later; it is
most of the remaining latency, and no amount of engine tuning substitutes for it.

## The 60 commands

Classifying each command by what would answer it:

| Bucket | Commands | Share |
| --- | ---: | ---: |
| Answered now, in one call | 9 | 15% |
| Improved now, but needs a follow-up call | 9 | 15% |
| Needs Phase 1 (index, `locate`, inherited members) | 8 | 13% |
| Needs Phase 2 (structural query) | 16 | 27% |
| Needs Phase 3 (references, inference) | 3 | 5% |
| Needs Phase 4 (framework profile) | 6 | 10% |
| Anti-scope — stays with grep | 9 | 15% |

Eleven of the Phase 0 cases were then run on both sides. The result is genuinely mixed, and the
pattern in it is the useful part:

**Where the original guessed a context size, the tool wins outright.** A `-A 20` or `-A 60`
guess either over-fetches or silently truncates; an exact span does neither. Those cases came
in 33–42% smaller, and one — a 2,499-line test file reduced to its outline — is the shape
`outline` was designed for.

**Where the original under-fetched, the tool costs more, and the comparison flatters grep.** A
search that printed only a signature line looks cheap next to one that printed the method. But
the corpus records single commands, so it cannot show the second round trip a reader then paid
to actually see the body. Asked the same question — `show --without-bodies` — the two are within
a few dozen bytes, and the tool additionally reports where the body ends.

**`show` is often larger than grep, on purpose.** It includes the docblock, because the docblock
is part of the method. In one case that docblock explained why a `blank()` check had been
deliberately avoided — the exact reasoning a reader needs and a `-A N` window drops.

**One of the eleven original commands returned nothing at all.** Its filters were wrong, and it
would have been read as "no matches" rather than "bad query". That is not a token comparison;
it is the failure mode grep has and a parser does not.

**And one case the tool simply should not win.** Reading one known property from one known file
is two lines of grep, and `members` answers a much bigger question at a much bigger price. The
right shape is `show 'Class::$property'`, which is comparable — and the properly targeted answer
is Phase 4's `model`. Not every search wants a code-intelligence tool.

## Changes this measurement produced

**The classmap is now loaded lazily, after PSR-4 arithmetic.** This inverts Composer's own
precedence, and the justification is the measurement above: without `dump-autoload -o` the
classmap cannot contain application classes, so loading tens of thousands of entries to answer
a question about application code is pure cost. It is a single PHP array whose `require` was
33 ms — more than a third of the tool's entire working time. Map construction is now 6–10 ms.
The two orderings can only disagree when two files declare the same fully-qualified name, which
is a duplicate-class bug rather than an ambiguity, and every candidate is verified against the
parsed declaration either way.

**The content scan is now opt-in behind `--scan` when a classmap exists.** Reading every file's
bytes to find a class that shares a file with a differently-named sibling cost 1.3 seconds, and
it was being paid on *misses* — the slowest possible answer being "no". Everything cheaper
accounts for all but roughly a quarter of one percent of declarations. When Composer has not
dumped a classmap there is nothing else left to try, so the scan still runs unasked. When it is
skipped, the tool says so and names the flag.

**Anonymous classes are now reported rather than silently omitted.** They cannot be addressed by
name, so they are not listed as though they could be; they appear in the trailing block with
their line and member count. Hiding them meant a file outline could show a class's methods and
quietly drop an anonymous sibling's — incomplete in exactly the way the output contract
forbids. Finding them was folded into the existing traversal after a separate pass measured 14%
slower across the corpus.

**Values are capped on character boundaries, and the JSON encoder no longer fails silently**
(see above).

**Dense property signatures carry scalar defaults** (from the earlier pass over the same
corpus). Several of the sixty commands search for a table name, a connection or a lookback
window, and in every one of them the value is the answer.

## What it says about the roadmap

The largest single bucket is Phase 2 structural query at 27%, consistent with the 2,170
multi-stage pipes the original audit counted. The next large tranche of value is the query
language, not more read commands.

Phase 0's own fully-answered share, 15%, is more pessimistic than §3.2's projection of "roughly
3,000 of the 3,577 measured searches". The two are not in direct conflict — that figure counts
searches whose *intent* is a declaration lookup, while this sample was selected for being
representative, which weights it towards the hard composed pipelines. It is the more pessimistic
reading and it is recorded as such rather than smoothed over.

## Phase 1 — the index

| | |
| --- | ---: |
| Cold build (6,536 files, 54,386 symbols, 8,822 inheritance edges) | 14.0 s |
| **Second checkout of the same content** | **1.3 s** |
| …files re-parsed for it | **0** |

That second row is the design justifying itself. Facts belong to a file's contents rather than
its path, so a worktree checked out from the same commit shares them. A cache living inside a
worktree would die with it, and every disposable checkout would pay the full build — which is
the most likely reason the obvious prior art was never used here.

### Time to first answer

| | |
| --- | ---: |
| `php -n -r ''` (bare interpreter) | 53 ms |
| `locate` by fully-qualified name | 106 ms |
| `subclasses` | 106 ms |
| `locate` by short name | 144 ms |
| `members`, resolved through the hierarchy | 205 ms |
| `overrides` | 212 ms |

Against a <200 ms budget, with ~53 ms of every figure being the PHP interpreter starting.

Getting there took two corrections, both found by measuring rather than by reasoning. The
obvious way to satisfy "validate on every query" is to walk the tree first, and that measured
**83 ms** on this project — most of the budget spent proving that files nobody asked about have
not moved. Validation is scoped to the answer instead: the files a result actually came from are
stat-ed and re-read if they have changed, and a query that finds *nothing* escalates to one full
pass, since a newly written class is indistinguishable from a missing one otherwise.

The second correction: an early version escalated on *every* lookup, including the internal ones
made while walking a hierarchy. That made `members` pay for a full corpus sync whenever a class
extended something un-indexed — which is the ordinary case for anything extending a framework
base. Escalation is now scoped to names the caller actually typed. `members` went from 324 ms to
205 ms.

### On the daemon

REPORT.md §5 lists a persistent daemon as part of this phase. It has not been built, and the
measurements are the reason: warm lookups already sit inside the budget without one, and the
largest remaining term is not the engine but the PHP interpreter starting — 53 ms on a lean
configuration, and 172 ms on this machine's default, which loads 69 extensions. A daemon is the
right answer to *that*, and it remains the right answer, but it is now a change with a known
size and a known payoff rather than an assumption. Building it before the engine was fast enough
to need it would have hidden both of the corrections above.

## Phase 2 — structural query

The audit's own worked example, run against the same corpus. Four chained text filters
approximating "call sites where the first argument is not a literal string":

| | grep | exocomp |
| --- | ---: | ---: |
| Output | 1,747 B / 12 lines | 304 B / 3 lines |
| Correct | **4 of the first 4 lines are comments** | 3 real call sites |

A text filter cannot tell a docblock from a call, so two thirds of what it returned mentioned
the method rather than calling it. The structural form returns the call sites, in **83% fewer
bytes**, with nothing to eyeball.

That first took **12.6 s**, because it parsed all 6,536 files. The index now records every name
a file *mentions*, so the candidate set is whatever mentions the names a pattern requires
literally:

| | |
| --- | ---: |
| Files opened | 6,536 → **12** |
| Time | 12.6 s → **280 ms** |
| Index build cost | +11% |

The filter is exact rather than heuristic — only names written literally count, and a pattern
with none gets the full sweep it actually needs. The same name index is what reference finding
needs, so it was paid for once.

## Phase 3 — inference and references

Two performance corrections again, both from this corpus. Looking for references to a method
that is *declared* but never called was not opening the file that declares it, because the name
index recorded only names a file referred to; declarations are mentions too. And the hierarchy
walks were not cached, so every call site re-walked the target's ancestry and re-queried the
database:

| | |
| --- | ---: |
| `references-to` on a widely-used member | 10.9 s → **2.5 s** |
| `references-to` on a framework method | 31.2 s → **4.8 s** |

Reference finding remains the most expensive thing the tool does, which is expected: it is the
only operation that must parse every file that could possibly mention a name.

A third fix came from running a broad scoped query over 1,659 test files: it exhausted the
default memory limit. A match holds AST nodes, so accumulating matches across a sweep pins every
tree that produced one. Matches are now reduced to plain values before the next file is opened.

### The gate

REPORT.md §5 makes this phase's exit criterion a hard one: **recall validated against grep over
the corpus — every textual hit grep finds must be either classified or explained.** Nothing on
the write side may start until it passes.

Twenty-five methods were sampled at random from the index, and for each, a word-boundary grep
over the whole application corpus was compared against what the tool reports:

| | | |
| --- | ---: | ---: |
| grep hits | 372 | |
| matched by a reference | 74 | 19.9% |
| explained: a comment | 31 | 8.3% |
| explained: not a member position at all | 140 | 37.6% |
| explained: a same-named member on another class | 126 | 33.9% |
| **unexplained** | **1** | **0.27%** |

The single unexplained hit was inspected: `$this->abilities` inside a service class, where the
target was a property of the same name on an unrelated model. It is a correct exclusion — the
oracle failed to classify it because that check only looked at methods, not properties. So the
genuine unexplained count is **zero**.

Getting to a defensible number took two corrections to the *oracle*, both worth recording
because both would have been easy to publish as tool failures:

- Dropping grep's word boundary made it match `getMembersInTeams` when looking for `getMembers`,
  and reported **53% unexplained** for hits that were never references.
- Counting every textual occurrence, rather than only those in a member position, made a
  method name that is also a common English word — `abilities` — match local variables,
  parameters, array keys and URL fragments. That reported **23% unexplained**, again for hits
  that were never references.

That is worth stating plainly: a word-boundary grep is not by itself a usable oracle for this,
and the two-thirds of hits it produces that are not references are exactly the noise the tool
exists to remove.

### Resolution and honesty

§6.0 asks for two numbers rather than one, and only the second is a correctness property:

| | |
| --- | ---: |
| **Resolution rate** — of all references, how many are `resolved` | **54.1%** |
| **Honesty rate** — of everything claimed `resolved`, how many are | must be ~100% |

54% is the coverage figure, and it is expected to start there and climb; the framework profile
is where most of the remainder lives, since a receiver arriving through a container binding or a
facade is not resolvable from the language alone. Every one of the other 46% carries a reason,
which is the property that makes the number safe to publish rather than embarrassing.

## Phase 4 — the Laravel profile

Everything here is derived by parsing ordinary PHP and SQL. Nothing boots the application, and
that is the constraint the phase was designed around rather than a limitation discovered
afterwards: the disposable worktrees this tool exists for routinely have no working `.env` and
no reachable database, and a profile that needs one is unavailable exactly when someone is
spelunking an unfamiliar branch.

### What the corpus contains, in one call each

| Question | Answer |
| --- | ---: |
| Tables the migration history describes | 351 |
| Routes across seven route files | 1,002 |
| Container bindings across thirteen service providers | 37 |
| Morph map entries | 12 |
| Classes extending `Illuminate\Database\Eloquent\Model` | 329 |
| Relations on the largest model, with their target classes | 52 |

### The exit criterion

REPORT.md §5 sets it as: **the top framework intents from §1.4 answerable in one call, and the
dispatch, binding and facade edges available to the graph layer.**

| Intent (§1.4) | Searches | Now |
| --- | ---: | --- |
| Facades | 678 | `facade Storage::disk` — accessor, container key, concrete class, and the method's declaration |
| Config/env keys | 307 | `config <key>` — definitions and every read |
| Model internals (`$table`, `$fillable`, `$casts`) | 139 | `model <class>` |
| Migrations → schema | 108 | `schema <table>` |
| Service providers | 91 | `bindings` — the registration half |
| Jobs & queues | 34 | `dispatches <class>` |
| Events/listeners | 31 | `dispatches <class>` |
| `firstOrCreate`/`updateOrCreate`/`upsert` | 31 | `schema <table>` for the unique index, `query` for the call sites |
| Global scopes & traits | 26 | `model <class> --only scope` |
| Container bindings | 22 | `bindings` |
| Route tables | 13 | `routes` |
| Entity lookup by uuid / scoping concerns | 1,361 | Partly. `model --only scope` and `schema` answer the scoping half; the rest is a query about data, not code |
| Factories | 281 | **No.** `database/factories` is not read |
| Policies & abilities | 21 | **No.** Gate registration is not read |

Eleven of fourteen intents answered directly, one partly, two not at all. The two gaps are
recorded rather than quietly skipped: factories and policy registration are both readable the
same way everything else here is, and neither has been built because nothing has needed them
yet.

The graph half of the criterion holds: `bindings`, `facade` and `dispatches` all emit
`file:line:col` edges with a named class at the far end, which is the shape Phase 5's `reaches`
consumes.

### Time to first answer

Best of three, warm index, against the 950k-line corpus:

| | |
| --- | ---: |
| PHP interpreter startup, doing nothing | 167 ms |
| `locate` (Phase 1, for comparison) | 228 ms |
| `routes` — 1,002 routes from seven files | 284 ms |
| `model` — a model declaring its table | 320 ms |
| `model` — a model whose ancestry runs into `vendor/` | 436 ms |
| `dispatches` | 465 ms |
| `schema` — one table, or all 351 | 484 ms |
| `morph-map` | 647 ms |
| `bindings` — thirteen providers | 653 ms |
| `facade Storage::disk` | 828 ms |
| `config <key>` — definitions *and* corpus-wide reads | 1,452 ms |

Interpreter startup is 167 ms of every one of those, and the framework answers do not meet the
<200 ms budget Phase 1 set for symbol lookup. That budget was set for the index, and these do
not read the index: migrations, config files, route files and service providers are found by
convention rather than by autoloading, and half of them declare no class at all, so they are
parsed on every call.

The obvious fix is to cache framework facts against file content hashes, exactly as file facts
already are — a squashed schema dump and 384 migrations hash to the same blobs on every run.
That is deferred, on the same reasoning as the daemon: nothing yet demonstrates the cost
matters, and a cache is a second thing that can be stale. What is *not* deferred is anything
that made an answer wrong.

`config` is the outlier and it is honest about why: the read half is a corpus scan, because a
config key is a string literal and the index holds names, not literals. It prefilters on the
key's first segment in its quoted shape — a quote, the segment, then a dot or the closing quote
— which took a key whose first segment is two letters from **14.1 s to 1.7 s**, since a short
segment is a substring of half the words in a large application. That is a narrowing rather than
a heuristic: every literal read begins with that shape, so no file that would have matched is
excluded.

### What running against the real corpus changed

Every one of these was found by pointing the tool at a real application rather than at the Tier
E fixture, and every one of them made an answer *wrong* rather than merely incomplete.

**The migration directory is not the schema.** A mature application squashes its history into
`database/schema/*.sql`, and the framework loads that before replaying anything. Reading only
`database/migrations` found 64 tables where the dump beside it describes 284 — so `users` would
have come back with four columns, stated as fact. The dump is now the base the migrations build
on, in that order.

**`Schema::table(self::TABLE, …)` is ordinary.** Reading the name as "not a literal" dropped an
entire migration silently, losing a column that the answer then reported as absent. Constants
the migration declares are resolved, and a table name that still cannot be read is counted and
reported rather than skipped.

**An unqualified `config()` inside a namespace resolves to `App\Foo\config`.** Matching on the
resolved name found nothing in any file that has a namespace — that is, in all of them. A
lookup of a key read in four places reported zero of them. After the fix it reports four for
four.

**`facade Storage::disk` said `FilesystemManager` does not declare `disk()`.** It does. The
method was being looked up in the index, which does not hold `vendor/`, so a gap in what had
been indexed was being reported as a fact about the framework. Methods are read from the class
itself now, located through Composer's autoload map, and "not declared" is only said about a
class that was actually read.

**A model was reported as not being an Eloquent model.** It was one — it extends a framework
class two hops from `Model`, which is the ordinary shape for anything authenticatable. The index
held an edge pointing out of the corpus and the walk stopped there. The same autoload-map
fallback fixes it, and asking that model for its scopes goes from a refusal to three named
global scopes, which is precisely what a cross-tenant audit wants.

**`subclasses Illuminate\Database\Eloquent\Model` was refused** for the same reason, despite
the edges being present — they are recorded by the files declaring the children. It now answers
329.

**Route files hid two thirds of their endpoints.** Three shapes each swallowed a batch: the
`'namespace' => …` group with bare controller names, the `['uses' => …, 'as' => …]` options
array, and `Route::resource()`, where the framework generates seven endpoints from one line.
Handling them took the same seven files from **414 routes to 1,002**, with none left unreadable.

**A hand-rolled pluraliser is not good enough for table names.** Reproducing the textbook rules
agreed with the framework on 92.5% of 306 real model names. The 7.5% it got wrong were
uncountables (`metadata`, `equipment`), already-plural class names (`Settings`, `TeamsToMembers`)
and an over-eager `-f` → `-ves` rule that turned `Chef` into `cheves` — which is to say, exactly
the names nobody can guess, and therefore exactly the ones someone would be looking up. The
profile uses `doctrine/inflector`, the library the framework itself uses: **306 of 306**.

**Short names collide harder than expected.** One corpus has five distinct classes sharing a
single short name — five queued jobs, in different namespaces, all called the same thing.
Answering about the first is correct as far as it goes; answering silently is how someone reads
an answer about a class they were not asking about. The others are now named in a note.

### Eloquent's `__get`, which Tier E allows the profile to resolve

`$order->customer` is the relation method `customer()`, reached through `__get`. Plain PHP says
that line is a property access on a class declaring no such property and stops there — so a
rename of the relation that ignores these breaks at runtime, which is the failure §8 is about.

`references-to` already found it: the reference scanner matches on the member's name and the
receiver's type, not on the shape of the access. What it did *not* do was distinguish the two:
`$order->customer()` and `$order->customer` both came back `resolved`, and the second is not a
fact the engine can see. A property fetch naming a method now comes back `inferred`, carrying
the reason:

```
$order->customer()   resolved   receiver is App\Models\Order
$order->customer     inferred   receiver is App\Models\Order; read as a property, but the
                                member is declared as a method — this resolves through __get,
                                if the class defines one
```

Recall is unchanged, because nothing was dropped; what changed is the claim made about one of
the two. The distinction is stated without the engine knowing anything about Eloquent: a class
with `__get` resolves it, a class without one has a bug there, and which of those it is cannot
be seen from the access.

### What the mutation gate said about Phase 5

It said the same thing it said about Phase 4, in the same words, and the value of a gate that
repeats itself is that the second time you recognise it.

| | Mutants | MSI | |
| --- | ---: | ---: | --- |
| Phase 4, as committed | 5,814 | 72.82% | |
| Phase 5 landed | 6,703 | **71.85%** | passed the 71 threshold by 0.85 |
| After asserting records rather than substrings | 6,711 | 72.85% | |
| After removing a guard that could never fire | 6,704 | **73.24%** | |

71.85% was a pass. It was also a drop, and the escape distribution said why: `ReachesCommand`
83, `Walk` 78, `LaravelEdges` 63 — the new code, tested through the CLI with
`assertStringContainsString`, which asserts that a string appeared somewhere and nothing about
the record it appeared in. Eighty-three of those escapes sat in a single row-rendering
expression.

Writing the tests the escapes pointed at found four behaviours with no coverage at all — the
node budget, a cycle, a declaration reachable two ways, and the identity of a stop — and one
output bug that said "1 edge led into a dependency and *were* not walked past", in three places.

Reading what still escaped in the walk afterwards was the part worth doing. `trace()` carried a
loop bound guarding against a cycle in the parent links, and the parent links are a tree: a key
is written once, when its node is first enqueued, and an enqueued node is never enqueued again.
The guard could not fire. The one construct that could have made a cycle — recording the
arriving edge before tracing through it — was removed instead, so the invariant holds by
construction rather than by being defended against.

That is the argument for treating the gate as a gap-finder rather than a number. Both times it
dropped, the escapes were concentrated rather than diffuse, and both times following them found
something real.

The threshold moves from 71 to 72, the first ratchet up in the project. Two consecutive phases
have now finished above 72.8, so it is earned rather than aspirational — and it stops short of
73 for the reason that kept it at 71 before: a threshold within a point of the measurement makes
the next commit a coin toss rather than a check.

### What it still declines, on purpose

Read against the corpus, these are the honest limits rather than a to-do list:

- 4 migration statements run raw SQL against a table nothing in the file names. They are counted
  and reported; the tables they touch are not knowable from the source.
- A binding built by a factory that constructs more than one class is declined. Following it
  would mean evaluating the factory.
- `morphTo` relations name no class. The morph map says what they *can* point at, and that map's
  keys live in the database.
- Listener discovery by parameter type is `inferred`, not `resolved`: whether discovery is
  switched on is not something the source says.
- Macros are unresolvable and are reported as such — `DB::table()` comes back as forwarded
  rather than as a method that does not exist.

### What the mutation gate said about Phase 4

Phase 4 took the mutation score from 73.3% to **69.35%** (5,772 mutants), below the 71%
threshold. The distribution is what made this worth acting on rather than re-baselining: unlike
the diffuse escapes of Phases 0-3, **840 of the 1,769 survivors sat in six framework reader
classes**.

That is a specific gap with a specific cause. The readers were tested end to end through the
CLI, which asserts that a string appears somewhere in a command's output — so nothing was
checking that a column had the right type, or that the line number reported for it was right.
Every address in a `schema` answer is something a reader is expected to click; reading the dump
correctly and reporting the wrong line for it is its own way of being confidently wrong.

Unit tests for the readers took it to 71.57%, and a second batch covering the container, model,
dispatch and config readers to **72.82%**. Four defects turned up in the writing, three of them
reaching the output:

- `Route::get($uri, ['uses' => …, 'as' => 'api.health'])` lost its name, because the options
  array was read for the action and nothing else — and a name is what a route is most often
  looked up by.
- `$table->unsignedInteger(…)` was reported as type `unsignedinteger`. Dispatch lowercases the
  Blueprint method and the type was being taken from the lowered form, so the answer printed a
  method the framework does not have.
- `SchemaDump::read` emitted a PHP warning on a missing file before returning empty. A tool that
  prints warnings into its own output has corrupted the answer it was asked for.
- An `instanceof` against an unimported class name, which PHP evaluates as false rather than
  failing, leaving the branch silently dead. PHPStan catches this; the test caught it first
  because the gate had not been run since the method was written.

The threshold stays at 71 rather than being ratcheted to 72.8. Ratcheting to within a point of
the measurement makes the next commit a coin toss rather than a check.

## Phase 5 — reachability

### The exit criterion

REPORT.md §5 sets it as: **`reaches` reproduces, in one call each, conclusions that previously
took a manual endpoint-by-endpoint sweep.**

The sweep, run against the corpus:

```
$ exocomp reaches 'route:<a prefix>' '::save'
25 of 100 starting points reach ::save
```

A hundred endpoints, each walked for a path to any model write, in 22 seconds. By hand that is
a hundred separate traces through controllers, services, jobs and listeners — the shape §3.4
describes as "a manual, repeated evaluation of exactly that question, endpoint by endpoint".

One of the positives, traced in full and then verified by hand at all three sites:

```
$ exocomp reaches '<a controller>::index' '::save'
<controller>::index reaches ::save in 3 steps; the weakest step is resolved
app/Http/…/…Controller.php:169:13  dispatch     →  <a queued job>::handle
app/Jobs/…/…Job.php:43:17          method call  →  <a service>::suspendAccount
app/Services/…/…Service.php:437:13 method call  →  <a model>::save
```

The second step is the one no language-only tool reaches: the controller calls
`SomeJob::dispatch($contract)` and execution continues in a different class, in a different
process. Reading the controller tells you nothing about the model that gets written.

A second positive, picked from the same sweep and checked the same way, is two ordinary steps —
a controller calling an injected service, the service saving a model 1,300 lines into the file.
That one a reader could have found by hand; the point of checking it is that the tool did not
invent a step to reach a conclusion it had already drawn.

Neither path was accepted on the tool's word. Both were opened at every line the answer named,
because a reachability verdict is exactly the kind of claim that is expensive to disbelieve and
worse to trust wrongly.

None of the 60 benchmark commands is a reachability question, and that is not evidence against
the phase — it is the shape of the evidence. A reachability audit appears in a shell history as
*dozens* of ordinary searches repeated with different arguments, never as one command, which is
precisely why §3.4 names it as the highest-value query rather than the most frequent one.

### Time to first answer

Best of three, warm index, against the 950k-line corpus, with a 189 ms PHP startup floor:

| Command | |
| --- | ---: |
| `routes` (now index-backed, for the controller namespace) | 333 ms |
| `reaches`, negative, graph exhausted | 245 ms |
| `graph --depth 3` from a controller (78 declarations) | 3,959 ms |
| `reaches`, path found in 3 steps | 3,974 ms |
| `reaches`, negative, budget hit after 127 declarations | 8,035 ms |
| `reaches route:…`, 19 endpoints | 12,866 ms |
| `reaches route:…`, 100 endpoints under one prefix | 21,902 ms |
| `reaches route:*`, the first 100 of the whole table | 35,425 ms |

Two things are worth reading off that table.

The first is that a **negative answer can be the fastest one**. 245 ms is a complete search: the
walk ran out of graph rather than out of budget, and every edge it met was followed. That is the
strongest thing this command can say and it costs nothing, because a small subgraph is small.

The second is that **a sweep gets cheaper per endpoint the more of one subsystem it covers**:
677 ms each across 19 endpoints under one prefix, 219 ms each across 100 under the same one, and
354 ms each across the first 100 of the whole table, which span more distinct code and so share
fewer parsed files. The caches are shared across starts, so the second walk through a service
pays only for the nodes it has not already parsed. The fixed cost — index validation, the
container read, the route table — is about 1.7 seconds and is paid once.

`graph` is bounded by the dependency wall rather than by its depth setting, which is what makes
a default of six steps safe to hand someone. From one controller on the corpus: 45 declarations
at two steps, 78 at three, 110 at four, 137 at six. Without the wall the fourth step would be
most of the framework.

These are far above Phase 1's sub-200 ms index budget, for the same reason the Phase 4 answers
are: a walk parses files by convention rather than reading rows, and nothing about a walk is
cached between runs. The fix is the same one Phase 4 deferred — cache derived framework facts
and expanded bodies against content hashes — and it is still deferred, because the thing being
replaced is a day of manual tracing rather than a sub-second query.

### What running against the real corpus changed

Five defects, none of which the fixture would have shown, and two of them in Phase 4 code that
the graph was the first thing to actually depend on.

**Two-thirds of the route table pointed at classes that do not exist.** A controller named by a
string is relative to a root namespace declared on the application's own route provider, and
without it the reported name is unusable — `reaches route:*` could not start from most of the
table. The root is now read from the provider rather than assumed, because recent framework
versions default it to nothing and assuming the historical default would be wrong in the other
direction. The first fix then produced names with the root written twice, because a string
beginning with a backslash is already absolute and the framework checks for exactly that.

**"Method not found on X" was two different statements.** One controller's walk produced twenty
of them, all the same Eloquent forwarding: a model does not declare `select()`, its base class
in `vendor/` does, and the call arrives there through a magic `__call`. Reported as "not found"
that reads as "your code is broken". A reader who checks one and finds the tool mistaken stops
trusting the rest of the output, which is the entire failure the confidence model exists to
prevent. The answer now names the class the readable ancestry stops at.

**Negative lookups were not cached.** `??=` re-runs whenever the cached value is null, and null
is the common answer when walking a framework application: hundreds of names per walk are not
in the corpus, and each retry was an index lookup, an autoload-map lookup and a stat. The
nineteen-endpoint sweep went from 26 seconds to 13, with system time falling from 17 to 6.

**A note was printed once per node.** Twenty identical lines of "a content scan was not run"
ahead of the answer. At that point it is not a remark, it is the output.

**Every distinct receiver type cost a file parse**, to establish whether it was an interface or
a facade — facts the index already records. Deciding both from the index instead removed a parse
per class encountered.

### What the mutation gate said about Phase 5

It said the same thing it said about Phase 4, in the same words, and the value of a gate that
repeats itself is that the second time you recognise it.

| | Mutants | MSI | |
| --- | ---: | ---: | --- |
| Phase 4, as committed | 5,814 | 72.82% | |
| Phase 5 landed | 6,703 | **71.85%** | passed the 71 threshold by 0.85 |
| After asserting records rather than substrings | 6,711 | 72.85% | |
| After removing a guard that could never fire | 6,704 | **73.24%** | |

71.85% was a pass. It was also a drop, and the escape distribution said why: `ReachesCommand`
83, `Walk` 78, `LaravelEdges` 63 — the new code, tested through the CLI with
`assertStringContainsString`, which asserts that a string appeared somewhere and nothing about
the record it appeared in. Eighty-three of those escapes sat in a single row-rendering
expression.

Writing the tests the escapes pointed at found four behaviours with no coverage at all — the
node budget, a cycle, a declaration reachable two ways, and the identity of a stop — and one
output bug that said "1 edge led into a dependency and *were* not walked past", in three places.

Reading what still escaped in the walk afterwards was the part worth doing. `trace()` carried a
loop bound guarding against a cycle in the parent links, and the parent links are a tree: a key
is written once, when its node is first enqueued, and an enqueued node is never enqueued again.
The guard could not fire. The one construct that could have made a cycle — recording the
arriving edge before tracing through it — was removed instead, so the invariant holds by
construction rather than by being defended against.

That is the argument for treating the gate as a gap-finder rather than a number. Both times it
dropped, the escapes were concentrated rather than diffuse, and both times following them found
something real.

The threshold moves from 71 to 72, the first ratchet up in the project. Two consecutive phases
have now finished above 72.8, so it is earned rather than aspirational — and it stops short of
73 for the reason that kept it at 71 before: a threshold within a point of the measurement makes
the next commit a coin toss rather than a check.

### What it still declines

Named rather than discovered later:

- **Eloquent's magic forwarding.** `Order::where(...)->update(...)` runs on a query builder
  reached through `__call`. Modelling it would mean reading the bodies of two magic methods and
  a `newQuery()`, and getting it subtly wrong is worse than declining: the walk reports the call,
  says the ancestry left the readable source, and stops. This is the single largest gap.
- **`__call`/`__callStatic` in general**, string callables, and any target computed at runtime.
- **Middleware, observers and policies.** §3.4 lists their registration sites as edges worth
  having and they are not built. All three are readable the same way the others are.
- **A widely implemented interface** is a fan-out rather than a path. The walk follows twelve
  implementations and says when it stopped.
- **Dependencies**, unless asked. The edge into one is recorded and checked against the
  destination, so a path that ends at a framework method is found; walking on is
  `--through-vendor`.

Every one of these shows up in the answer as a place the walk stopped, with its address and its
reason — which is the property that makes a negative verdict worth reading at all.

## The 60 commands, after five phases

The §9 classification, revisited now that the index, the query language, reference resolution and
the framework profile all exist. Phase 5 leaves it unchanged, for the reason given above: a
reachability audit appears in the corpus as repetitions of simpler searches rather than as a
command of its own.

| Bucket | Phase 0 | After Phase 3 | Now |
| --- | ---: | ---: | ---: |
| Answered in one call | 9 (15%) | 45 (75%) | **51 (85%)** |
| Needs the framework profile | 6 (10%) | 6 (10%) | 0 |
| Anti-scope — stays with grep | 9 (15%) | 9 (15%) | 9 (15%) |

The six framework-shaped commands were run rather than reclassified by reading, and two of them
failed the first time:

| # | Shape of the original command | Now |
| ---: | --- | --- |
| 5 | `grep -n 'protected $table\|protected $connection'` on a model file | `model <class> --only attribute` |
| 11 | `grep -n "'tmp'" -A8 config/filesystems.php` | `config filesystems.disks.tmp` |
| 23 | `grep -n 'function fake' -A18` on a framework facade in `vendor/` | `facade Storage::fake` |
| 32 | `grep -n 'class\|table\|…'` on a model file | `model <class>` |
| 34 | `grep -n 'dispatch\|Bus::\|chain'` on a queued job | `calls-from` / `dispatches` |
| 52 | `grep -nE 'protected $casts\|protected $fillable'` on a model file | `model <class> --only casts` |

**#11 came back "no config file defines filesystems.disks.tmp".** The file builds its array in a
variable and returns that, so a comment block can sit above each section — and reading only
`return [ … ]` reported every key in it as undefined. A confident answer about a file sitting
right there. The returned variable's assignment is now followed.

**#23 reported `fake()` as forwarded or macroed.** It is declared on the facade itself, twenty
lines below the accessor: `fake()`, `spy()` and `swap()` all live on the facade rather than on
the class it resolves to. The facade is now checked before the concrete class.

Both were caught by running the benchmark rather than reasoning about it, which is the
argument for keeping it.

Spot-checks against the real corpus rather than by reading:

- A search for a method declaration across the corpus (`grep -rn 'function importLedger' app/`)
  returns nothing, and so does `locate importLedger --kind method` — the original command was
  itself a fruitless search, and the tool agreeing is the right answer rather than a miss.
- `grep -rl 'schedule-first-run\|scheduleFirstRun\|ScheduleFirstRun' app` becomes
  `locate scheduleFirstRun`, which resolves the name to a fully-qualified one and a file.
- Counting repository lookup methods across the corpus becomes three `locate` calls reporting
  137, 32 and 0 declarations.

The 15% that stays with grep has not moved and should not: those are keyword hunts through prose
and string literals, and searches spanning `.vue` and `.js`. A PHP-aware engine has nothing to
add to them.

## Magic members

### What the corpus actually contains

The plan for this work was to analyse `__call` bodies and follow the forwarding. Counting first
overturned it. Over the `app/` tree of the corpus — 4,516 PHP files:

| Construct | Count | Where the evidence lives |
| --- | ---: | --- |
| `@property` / `-read` / `-write` | 4,155 | the class's own docblock |
| `@method` | 3,552 | the class's own docblock |
| `getXxxAttribute()` accessors | 271 | a declaration in the same class, named by convention |
| `@mixin` | 265 | the class's own docblock — 261 of them pointing at a generated helper |
| `scopeXxx()` local scopes | 180, 113 distinct names | a declaration in the same class |
| `protected function x(): Attribute` | 33 | same |
| `__get` bodies | 10 | a method body |
| `::macro()` registrations | 2 in `app/`, 30 in all of `vendor/` | a call site elsewhere |
| `__call` bodies | 1 | a method body |
| `__callStatic` bodies | 0 | — |

Roughly 99% of the magic surface is either an annotation or an ordinary declaration whose name
the framework transforms — both already in a file the parser has read. The general case is a
rounding error, and it is built last and marked optional.

`__invoke` is worth its own line: 758 in the corpus, 608 of them controllers. Those were already
handled — a route naming only a class resolves to its `__invoke` — which is why the count did not
drive any work here.

### The safety gate

Stated before building anything: **`references-to --resolved-only` may gain entries, and may not
lose or change one.** Everything the write side is allowed to touch has to come through this
work unaltered, or the phase is not shippable.

Run over 24 members of the corpus — twenty ordinary methods and four local scopes — comparing the
**resolved records** each binary emits, against the commit before any of this work:

| | |
| --- | ---: |
| symbols compared | 24 |
| resolved records lost | **0** |
| resolved records gained | **5** |

Every gain is a local scope reached by the name its callers write. Nothing left the resolved set.

The comparison is between resolved *records*, not between `--resolved-only` *output*, and the
difference is the second defect this gate found. `--resolved-only` dropped only the textual group
and printed the inferred one — while its own help called it "the references the tool will stand
behind", and the write side is gated on `resolved` alone. On six of these symbols it was emitting
nineteen inferred references a rename would have rewritten. Nothing had surfaced it because
inferred results were rare on the symbols anyone had tried; this work moved records out of
"unresolved" and into "inferred", where the flag was quietly emitting them.

The first thing it found was worse: `references-to` on a member of a model exhausted PHP's default
512MB and died rather than answering. The ladder walks the ancestry of every receiver type it
meets, and the class source retained each parse — file text and syntax tree — for 1,077 classes in
one search. Bounding that cache, and asking the ladder only when it could answer (the receiver is
related to the target, or the index says it has a `__call` or `__get` to forward through), took the
peak from 511MB to 177MB and the loads on a typical search from 1,077 to 31.

### What it found

The reference search now answers the inverse question, which it previously answered wrongly:

```
$ exocomp references-to '<a model>::scopeActive'
5 references  (5 resolved, 0 inferred, 0 unresolved)
   ... 4 call sites written as `::active()`, and the declaration
```

Before this work the same command returned the declaration alone, with full confidence. Four
call sites in one file were invisible, and a rename driven by that answer would have broken the
codebase at runtime rather than at build time.

**Latency.** The alternative spelling has to be searched for, and `active` appears in 719 files
of the corpus against 9 for `scopeActive` — an 80× wider search, which took **30.6s**. Pairing the
spelling with the owner's short name, which costs nothing in recall because a receiver that
*resolves* had its type written in the same file, took the same query to **3.5s** with the same
result. Both figures are the same binary before and after that one change.

**What it costs.** Measured against the pre-phase commit, same corpus:

| | before | after |
| --- | ---: | ---: |
| a typical member's references | 0.88s | 1.77s |
| another | 0.86s | 1.93s |
| the worst member found (`parent`, on a model) | 63s | 112s |
| peak memory on that member | 54MB | 177MB |

That is a real regression, and the narrowing above does not pay for it — it is what asking the
ladder costs. The remaining time goes on parsing a model's framework ancestry, and on the 917KB
generated helper reached through `@mixin` (0.12s to parse on its own). It is recorded rather than
optimised away because the next step is not obvious, and the number should not be discovered later
by someone else.

### A baseline that was not one

The first set of before/after figures for this work was wrong, and the way it was wrong is worth
recording because the setup is an obvious one to repeat.

The baseline was a `git worktree` of the earlier commit with its `vendor/` symlinked to the main
checkout's, to avoid a second install. Composer's generated autoloader computes its base
directory as `dirname(__DIR__)` from inside `vendor/composer/` — and `__DIR__` resolves symlinks.
So the baseline binary loaded `Vusys\Exocomp\` from the *main* checkout: both sides of every
comparison were the same code, and the differences measured were noise.

What gave it away was a result that was too clean. The safety gate came back with 25 of 25
symbols unchanged, on a sample that included four local scopes — three of which do have call
sites, and finding those is the whole point of the change. A gate that cannot fail is not a gate.

The fix is to give the worktree its own `vendor/composer/` and `vendor/autoload.php`, with the
package directories symlinked individually, then confirm by reflection which file the class
actually loads from before trusting a single number:

```console
$ php -r 'require "…/vendor/autoload.php";
          echo (new ReflectionClass(TypeResolver::class))->getFileName();'
```

### The drift report

`model` now holds four independent accounts of a model's attributes — the migration history,
`$casts`, the accessors and the `@property` block — and reports the annotated properties that
nothing else backs.

Run against 120 real annotated models, the rate went **3 of 40 → 3 of 120** as the false
positives were chased out:

| Cause | Fix |
| --- | --- |
| an aggregate attribute from `withCount()` | relation aggregates are accounted for by their relation |
| an accessor annotated in camel case, recorded in snake | the framework studlies whatever key it is given, so spellings are normalised |
| a `morphTo` relation | a declined relation is still a relation |
| a relation built into a variable and returned through a helper | read, when the method's declared return type is a `Relations\*` |
| a relation derived from another relation | recorded as declined — real, with no target readable from that method |
| a table whose migration history could not be read to the end | a knowingly incomplete column list cannot contradict a claim |
| a model whose parents or traits could not be read | the note says which, instead of implying the claim is groundless |

One survivor checked by hand: a model carrying `@property mixed $name` where the table has nine
columns and none is `name`, and the word appears nowhere else in the file — no cast, no accessor,
no relation. That is the case the report exists for.

## Re-running this

Point a checkout the corpus matches at the tool, then per command compare call count and output
size. The bar stays the one §9 sets: one call, fewer tokens, no truncation. The parse sweep and
the grep differential are worth re-running on any large codebase — they are the cheapest
correctness signal available, and neither needs a fixture.
