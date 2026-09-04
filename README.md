# sol-pay demonstrator

A working site that meters a small set of articles with
[sol-pay](https://github.com/wbreeze/sol-pay), on devnet, against a token it
issues itself.

Note: nothing is implemented yet. `SPEC.md` is the design; the platform
choices it leaves open in §12 are the next decision.

## Why this exists

`sol-pay-client` is a library that ships no application on purpose. Its spec
names three screens — `set_meter`, `manage_meter`, `metered_page` — and says
they belong to the integrator. This repository builds them.

It is meant to be read as much as run. Everything sol-pay declines to supply —
RPC, the wallet adapter, the session, the viewer-to-wallet map, the decision to
meter a request, error attribution, log hygiene — is here, in one place, in the
smallest honest form.

## Status

| | |
| --- | --- |
| spec | draft, 2026-09-02 |
| cluster | devnet |
| token | a demo mint, six decimals, worth nothing |
| implementation | none |

## Reading order

- `SPEC.md` §1–§2 — what this is and what it has to prove
- `SPEC.md` §7 — the metering decision, which is the part sol-pay leaves to the
  site and the part an integrator comes here for
- `SPEC.md` §12 — the platform choices still open

## Licence

Dual licensed under either of Apache License, Version 2.0 or the MIT license,
at your option — matching sol-pay.
