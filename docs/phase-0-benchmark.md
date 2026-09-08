# Phase 0 measured against a real corpus

REPORT.md §9 proposes a cheap acceptance test: 60 genuine PHP search commands lifted unedited
from real transcripts, with one bar per command — **answer it in one call, in fewer output
tokens, with no truncation.** Anything that cannot be answered that way is either a missing
capability or a deliberate anti-scope decision, and either finding is useful.

This is that measurement, run against the private Laravel monolith the commands came from:
6,920 application files (949,910 lines, 33.7 MB) and 30,910 vendor files (172.5 MB).

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

## Re-running this

Point a checkout the corpus matches at the tool, then per command compare call count and output
size. The bar stays the one §9 sets: one call, fewer tokens, no truncation. The parse sweep and
the grep differential are worth re-running on any large codebase — they are the cheapest
correctness signal available, and neither needs a fixture.
