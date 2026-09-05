# sol-pay demonstrator

A working site that meters a small set of articles with
[sol-pay](https://github.com/wbreeze/sol-pay), on devnet, against a token it
issues itself.

`SPEC.md` is the design, and §12's platform choices are all closed: PHP with
Slim 4, SQLite, the public devnet endpoint, called from the server.

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
| spec | draft, 2026-09-02, revised through 2026-09-05 |
| cluster | devnet |
| token | a demo mint, six decimals, worth nothing |
| implementation | server skeleton, store and chain plumbing; no screens yet |

## Running it

```
composer install
bin/devnet-smoke          # airdrop, then one real transaction (see below)
php -S localhost:8000 -t public
```

PHP 8.1 or later, with `sodium`, `pdo_sqlite` and `curl` — all bundled. The
suite (`composer test`) needs 8.2, because PHPUnit 11 does; `composer install
--no-dev` resolves on the floor, which is how a reader on 8.1 would install.

`bin/devnet-smoke` is the first time `SolPay\Core\Tx` meets a validator.
Conformance proves `compile` and `wire` agree byte-for-byte with
`solana-message` and `solana-transaction` on three fixed cases; it cannot prove
a validator accepts the result, because no signature there is real, no
blockhash was ever current, and nothing has paid a fee. This sends one System
transfer and pays one.

Nothing under `var/` is committed: the SQLite file, and the devnet keypairs
setup generates. They control nothing of value and the site says so.

## Reading order

- `SPEC.md` §1–§2 — what this is and what it has to prove
- `SPEC.md` §7 — the metering decision, which is the part sol-pay leaves to the
  site and the part an integrator comes here for
- `SPEC.md` §12 — the platform choices still open

## Licence

Dual licensed under either of Apache License, Version 2.0 or the MIT license,
at your option — matching sol-pay.
