# Why these tests exist

Several files under `tests/Support/` do not look like tests. They scan source
text, read what a closure captured, or compare a rendering against a git ref.
Each one exists because something escaped every ordinary check and reached the
author's browser, or CI, or a published page. This is the list, so that a test
whose point is not obvious does not get deleted for looking strange.

`.github/workflows/ci.yml` is the authority on what a green run means.
`bin/check` runs what it can locally and says what it cannot.

## What escaped, and what catches it now

| escaped | caught by |
| --- | --- |
| `curl_close()` deprecated on 8.5, invisible on 8.4 | `ci.yml`'s 8.5 leg, which exists for this |
| `symfony/yaml` being only a *suggestion* of commonmark | `ci.yml` installs and runs `bin/build-content` on every push |
| the committed lock unresolvable on the stated floor | `ci.yml`'s `floor` job — constraints resolved on 8.1, the lock did not, and the lock is what a reader installs |
| a pragma answering `database is locked` instead of queueing, on a database that does not exist yet | `Metering\OneMeterAtATimeTest` — **but only on a cold runner**; see "a green run count" below |
| a closure capturing a helper defined below it | `tests/Support/FrontControllerTest.php` |
| a closure *not capturing* a helper it uses | the same file, which caught two real faults during the `RequestRead` refactor |
| a closure capturing what it no longer uses | `phpstan`, which found five leftovers in one slice |
| a `fn` closing over a variable the request later mutates by reference | the same file again, plus a companion that runs the scanner over a deliberately broken sample, so a green result cannot mean "nothing scanned" |
| a bareword array key (`chain =>`), valid PHP and fatal at runtime | `tests/Support/BarewordTest.php`, and now `phpstan` |
| a property access on a class with no such property | `ci.yml`'s `phpstan` job, level 5 |
| the wallet registry keyed by name rather than by feature | `/diagnostics/wallets` plus `usable(chain)` |
| 350 KB downloaded inside the blockhash window | both libraries preload before the click |
| a page spending an RPC call nothing on it needed | `var/profile-pages`, and a HAR read against written-down predictions |
| a payer address that starts depending on the site *account* | `tests/Chain/RequestReadTest.php` — derive the pair both ways, require the same strings |
| a pre-send account reading shown on a screen that reports a charge | `tests/Metering/MeterResultTest.php` — the sending factories must have nowhere to put a `PayerState` |
| a markdown syntax the dialect cannot render | `tests/Content/MarkdownTest.php`, whose third part is the one that matters: a syntax on *neither* list was invisible, which is exactly what a missing extension is |
| a `status` nobody recognises publishing a draft | `tests/Content/BuildContentTest.php` — the build refuses anything but `draft` or `published` |
| a published date that has stopped being true | `bin/content-dates`, with `--require-git` in CI so a green result cannot mean "no history to check" |
| a refactor that moves the page while the suite stays green | `bin/render-diff`, 88 fixtures rendered from this tree and from a ref |
| a prerendered page charging for an article nobody opened | `PrerenderTest` and `MeterMiddlewareTest` — two checks at opposite ends of one request |
| a session cookie travelling with another site's POST | `SessionCookieTest`, which pins `SameSite=Lax` |
| an expiry that hides a row nobody deletes | `ChargeSweepsTest` for the charge-time sweep, `SweepScriptTest` for the scheduled one |
| a GET route that can reach a `Meter` | `tests/Support/RouteTest.php`, which reads what each route's handler closed over rather than the names a textual check happens to know |
| a second confirmation schedule anywhere | `ConfirmScheduleTest`, which asserts that only `Submitter` calls `signatureStatuses` |
| a delegate another site's `approve` replaced, reading as present | `tests/Chain/PayerStateTest.php` and `DelegateWiringTest` — the library's `delegate_present` cannot answer it, so the comparison is the site's |
| a page title and its heading drifting apart | `tests/Support/PageTitleTest.php`, for the two pages where one phrase is written twice |
| a failure report carrying nothing about the failure | `tests/Support/FailureReportTest.php` — the challenge is recorded *before* the wallet call, because the call is what throws |
| devnet reset, or the program redeployed elsewhere | `bin/devnet-canary`, daily |
| this repository pinned behind a published `sol-pay-client` | `bin/upstream-drift`, daily |
| an Anchor event renamed upstream, changing its discriminator | `ProgramEventTest`, which derives all three rather than trusting a copy |
| the scheduled sweep silently stopping | **nothing.** `GET /health` reports `sweep.overdue` and somebody has to read it |
| a charge that lands and then fails, or never lands | **nothing.** `NEWSPRINT_CHARGE_FAULT` plus a devnet run, injected by hand |
| a to-do note that outlives the job | **nothing.** The only defence is reading the code before repeating the note |

## Two standing rules

**Verify a structural test in both directions.** Every one above fails on the
broken form rather than merely passing on the fixed one. That standard has
paid repeatedly: a capture check was once skipping every closure in the file
and passing, and the arrow-function check was confirmed by putting the `fn`
form back into `public/index.php`. Eleven mutations were run across one day's
repairs, each red on exactly the test that claimed it.

**A test may generate inputs, but it must not need luck.** `MessageSignerTest`
once generated up to 200 keys hoping one sorted below the fee payer, which
fails one run in 199 — measured over 200,000 trials — and turned CI red on one
leg of four with no defect behind it. Where a test needs a particular
arrangement of random values, construct the arrangement. Do not sample until
one appears.

## Predict, then measure

The method for any change about *how much work a request does* rather than
what it renders. It has run three times without a miss.

1. Make the change, with the suite and the analyser green.
2. **Write the expected `X-Rpc-Calls` for every route before the run**, and
   include the counts that must *not* move.
3. The author runs `NEWSPRINT_RPC_TIMING=1 bin/run-dev` and captures a HAR.
4. Read `X-Rpc-Calls`, `X-Rpc-Ms` and `X-Rpc-Detail` off every response.

The predictions are the point. A table of measurements alone cannot fail; a
table written beforehand can, and twice it has caught what reading the diff
did not — that the charging view was six calls rather than three, and that the
`set-meter` screen was paying for a read whose result was discarded.

Always include the counts that must stay the same. After the `RequestRead`
refactor, "a charging view must still be 6, and `/privacy` must still be 0"
was worth more than the counts meant to fall: either one moving would have
been a silent correctness fault rather than a slow page.

**The wall-clock half is `bin/profile-pages`.** `X-Rpc-Calls` counts the calls.
A count cannot say whether a slow page is our code or the endpoint, because one
call against the public devnet endpoint moves by an order of magnitude across a
day. The script times five things whose only difference is how many calls they
make — a static asset, `/a/privacy`, `/health`, the article, and a
`getLatestBlockhash` sent straight at devnet — then subtracts:

| the subtraction | what it is |
| --- | --- |
| `median(/a/privacy) - median(static)` | our code, with no chain in it |
| `median(/a/slug) - median(/a/privacy)` | the article's one chain read |
| that, over `median(endpoint)` | calls the article really makes |

The last row is the alarm. It should sit near 1.0, and drift upward means a
route started reading the chain without anybody deciding that it should. The
script needs a running server and the network, so it is the author's to run,
and one run says nothing on its own — compare runs, not hours.

**A subtraction it cannot resolve says so.** The first run after the baseline
was repaired put the render at `0.000 s`, against a baseline whose own eight
samples ranged over 2 ms — a difference well inside the noise of the numbers it
came from, printed in the same shape as a measured one. A difference smaller
than the spread of its two terms is now marked `(?)` and explained, not
reported. That run is also why everything is in milliseconds: three decimals of
a second had been rounding the render away before the subtraction ever
happened.

**The spread is the interquartile range, and the first attempt used `max -
min`.** That was wrong twice in one rule, and the next run showed both. A
static asset with a median of 0.54 ms and a minimum of 0.46 ms produced a floor
of 3.19 ms, because one request in eight took 3.65 ms — so a single scheduling
hiccup vetoed a difference between two otherwise steady medians. And the remedy
the script printed beside the veto made it likelier: more samples is more
chances at a long tail, so *raise `-n`* widens a range while it narrows a
median. The arithmetic subtracts medians, so the floor belongs to the middle of
the distribution rather than to its ends; under quartiles the same row's floor
is about 0.09 ms and `-n` helps as the advice always claimed.

The middle half is still conservative on purpose. It is the sample-to-sample
variation rather than the standard error of the median, which would be smaller
by roughly the root of the sample count. This is a tool for noticing that
something moved, not for publishing a figure, and a floor that errs toward
silence is the right kind of wrong.

**It was promoted out of `var/` on 2026-09-25, and it came with a defect worth
recording.** Its baseline was `/privacy`, which has been a 301 to `/a/privacy`
since 2026-09-14 — §10.2 asks that the URL carry the page rather than that a
second handler render it. A redirect does almost no work, so *our code* read
near zero, and every millisecond of the real render was charged to the
article's chain read instead. The row printed `!! http codes: 301 301 …` on
every run for eleven days, directly above an arithmetic block that averaged it
in regardless. **A guard that reports and continues is decoration.** A bad row
now refuses the subtraction and exits 1, and the row's test was tightened at
the same time: it passed when *any* sample was 200, so one good sample excused
seven bad ones.

## A green run count is a claim about a machine

`PRAGMA journal_mode = WAL` needs an exclusive lock, and SQLite does not
consult the busy handler to get it, so a database being created by four
processes at once loses some of them outright. That defect went 70 consecutive
runs green on the author's SSD, then turned six of eight legs red on one push.
In the container, CPU load alone reproduced nothing in 70 attempts; only an
explicit rendezvous before `new PDO` opened the window.

So when quoting repetition as evidence, say which machine. Do not treat
repetition on a fast one as coverage.
