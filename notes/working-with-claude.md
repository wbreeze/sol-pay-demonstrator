# Working on this repository with Claude

**Claude writes and reasons; the author runs.** Anything with a chain call, a
browser or a wallet in it is written to be run by hand and to print what it did.

`SPEC.md` decides things. Read the section before discussing the section.

## Git

**Reads are allowed. Writes are the author's.**

Prefix every read with `GIT_OPTIONAL_LOCKS=0` — `GIT_OPTIONAL_LOCKS=0 git
status`, and `git diff` too. A plain `git status` takes `.git/index.lock`, and a
session working through a connected folder cannot remove it, so the lock is left
behind for the author to trip over at the next commit. The prefix stops the lock
being taken at all, which is the whole of the fix.

No commits, no branches, no pushes, no other git write. Do not assume `master`:
the author works on branches.

`*.DS_Store` is in the author's global gitignore. It is invisible to him and
visible to a session on his machine, so do not report it as untracked and do not
add it to this repository's `.gitignore`.

## Where things run

Four environments, and only two of them reach a network.

| | PHP | network | git history | browser and wallet |
| --- | --- | --- | --- | --- |
| The author's macOS shell | 8.5 | yes | yes | yes |
| The VM behind a connected folder | no | none | yes | no |
| Claude's cloud container | 8.4 | proxied, no npm/packagist/devnet | no | no |
| GitHub Actions | 8.2–8.5 matrix | yes | yes | no |

Consequences worth knowing before planning any work:

- **`bin/content-dates` and `bin/render-diff` are always the author's to
  run.** Both need PHP and the git history together, and no single environment
  Claude reaches has both. - **The diagrams are the author's to render.**
  Plates rendered in the container carry DejaVu and do not match the committed
  set. - **Devnet, Composer, npm and `bin/vendor-assets` are his too.** -
  **The container serves as a CI stand-in.** Two of the four CI jobs reproduce
  there exactly: the pinned PHPStan PHAR downloads through its proxy, and the
  real PHPUnit runs the real test files. Build a tarball of the tree into
  `var/` (gitignored, so `git status` stays clean) and stage from there; `tar
  czf` over the existing file rather than deleting it, because deletion inside
  a connected folder is refused. **Include `content/`** — without it
  `bin/build-content` produces an empty index and `TemplateRenderTest` fails
  for a staging reason rather than a real one. - **More is reachable offline
  than it looks.** The browser transaction path runs in node once
  `public/vendor` exists. Phantom's real signed message replays through the
  PHP parser. The app boots against a stub RPC that logs every call, which is
  how call counts get checked by counting rather than by reading a diff. When
  a claim looks browser-only or chain-only, check whether the artefact can be
  brought to the container first. It has worked every time it has been tried.
  - *What is not reproducible offline is timing.** The container runs CI's
    software exactly and its scheduling not at all. A green run count is a
    claim about a machine, not about a race.

## Hazards, each one paid for

- **Verify every write back to the author's tree by hash.** A commit through
  the device bridge has reported success while the device kept the previous
  version. Comparing md5 on both sides is the only thing that catches it. -
  **The bridge does not carry the executable bit.** A file under `bin/`
  written that way arrives non-executable, and the md5 check does not notice,
  because it compares content. Files written in place with `cat >` keep the
  mode they had. - **GNU sed, not BSD**, in the VM behind the connected
  folder, even though the files live on a Mac: `sed -i` takes no argument
  there. - **The author's shell is comma-decimal.** A script that formats
  numbers must `export LC_ALL=C`. Under a comma-decimal locale bash `printf`
  refuses `0.766914` outright, and `sort -n` quietly stops sorting, which
  produces plausible and wrong medians. - **Quoting.** `node -e '…'` from a
  single-quoted shell string eats `'`; a JS template literal eats `\S`, `\C`
  and friends. Write patch scripts to a file with a quoted heredoc. -
  **`endforeach` contains `foreach`.** An assertion counting one finds the
  other. - **Never drive Chrome through DevTools when prerendering is
  involved.** Chrome reports `PrerenderingDisabledByDevTools`, so a Playwright
  version of `PrerenderTest` passes with the check deleted. - **Do not probe
  writability by writing a file into the tree.** Scratch goes in `var/`. -
  **Re-read a content file immediately before editing it.** The author edits
  prose between messages.

## Two rules about claims

**Read the code before believing a note about the code**, these notes included.
A to-do is a claim with no test behind it. This project's queue has been wrong
four times: two published drafts listed as unpublished, a diagram branch removed
five days earlier, and two poll loops that had not existed for six days. The
cheapest check — grep for the thing that is supposedly there — would have caught
the last one in seconds.

**A test written for a repair must be run against the unrepaired code.** A
repair test that passes before the repair is testing nothing. And a function
with a passing test proves nothing about whether anything calls it:
`sweepExpired()` was tested for a week and never ran.
## Working with the author

- He tests by hand, thoroughly, and reports precisely. Take the report as fact
  about what happened; do not take it as a citation.
- **Give him predictions to test against**, not a request to look at
  something. See [`why-these-tests.md`](why-these-tests.md) for the method.
- He captures HARs, which settle browser claims outright. Ask for one rather
  than reasoning about what a script probably did.
- He does not want apologies for bugs. State the cause, fix it, move on.
- He reviews in batches against section numbers. Expect long pauses, then a
  lot.
- He pushes back on over-reaching claims and on rhetorical neatness. Check
  that an argument is true before checking that it lands.
- He proposes wording and expects it read rather than pasted. Push back when
  something is wrong with it.
- He asks for things to be traced rather than recalled. Search for the
  incident before acting on a recommendation; the incident is the argument.
- When the code and the SPEC diverge, say which and fix it in the same
  sitting. Flagging is the job; deciding is his.
