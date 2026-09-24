# CLAUDE.md

The working notes are in [`notes/`](notes/README.md), aside from the product.

Start with [`notes/working-with-claude.md`](notes/working-with-claude.md): the
environments, the git rules, and the hazards. Then `SPEC.md`, which decides
things — read the section before discussing the section.

Two rules that bite immediately:

- **Git reads only, and prefix every one with `GIT_OPTIONAL_LOCKS=0`.**
  Commits, branches and pushes are the author's.
- **Read the code before believing a note about the code**, these notes
  included. A to-do is a claim with no test behind it.
