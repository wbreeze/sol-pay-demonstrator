# notes

How this repository was made, and how to work on it. **None of it is part of
the demonstrator.** The product is `SPEC.md`, `README.md`, `content/`, and the
implementation under `src/`, `public/`, `templates/` and `bin/`. Nothing here is
built, served, rendered or tested.

**`SPEC.md` decides things. These notes do not.** Where a note and the
specification disagree, the specification is right and the note is stale. Say
which, and fix it in the same sitting.

| file | what it holds |
| --- | --- |
| [`working-with-claude.md`](working-with-claude.md) | The environments, the git rules, and the hazards that have cost real time. Read before a session touches this tree. |
| [`why-these-tests.md`](why-these-tests.md) | What offline checking cannot see, and which test or script catches each one. The argument for the odd-looking tests under `tests/Support/`. |
| [`writing.md`](writing.md) | Conventions for the articles and for on-screen copy: audience, person, byline, naming, and the prose rules. |
| [`deployment.md`](deployment.md) | The devnet deployment as it stands: addresses, parameters, clocks, event discriminators, and what a lost `var/` means. |

**Not here on purpose.** The decision log lives in `SPEC.md`, section by
section, because two copies of a decision drift and the stale one reads exactly
like a finding. That has cost this project three rounds already.
