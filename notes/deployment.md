# The devnet deployment

**One instance, not a repository fact.** The addresses below are the result of
first-run setup on the author's machine. `SPEC.md` §12.0 makes provisioning
the job of whoever runs a copy, so a second copy produces a different mint,
treasury and site account. `bin/devnet-canary` says as much when it runs
anywhere else: *not provisioned here, so this checkout's own accounts were not
checked.*

Recorded here because nothing else tracked records it, and because a
demonstration that cites a transaction needs the accounts named somewhere a
reader can check.

## The author's instance

| | address |
| --- | --- |
| site (`Site` PDA) | `7X4hDbm44UQYnmXshwSdCyAMhh3bJe2X5u1z2m1dSCVt` |
| mint (DEMO) | `AKRs35WnmePSDLcPLyVtC5UPPBC8xjmVZ4EUJ3XwU9op` |
| treasury | `3qZLoqdTunZJUANtRpaAnpFewAW4UVkgPn6VWzWTk4Mw` |
| authority | `163aJWGmry7Q2gWjtxmTbdC7NGFc7FecSN1gfpNUgRt` |
| faucet | `GCtMbPvNm3jvFb28jZPbqiGQ7aTAkomiiyjk2GqB1oyk` |

The metering program is a repository fact rather than an instance one: its id
is `SolPay\Core\Ids::PAY_ON_CHAIN_ID`, read by `config/site.php`, and one
deployment serves many sites because the `Site` PDA is seeded by the
authority.

## What a lost `var/` means

`var/` is gitignored and holds the keypairs setup generated — authority, mint
and faucet — as Solana CLI JSON. **They exist nowhere else.** Losing that
directory means the site above can never settle again, and readers recover by
closing their contracts, which forgives the residue and revokes the delegate.

That is the accepted ending. `SPEC.md` §4.4 says the demo holds a devnet key
controlling nothing of value, §15.4 states what rotation would cost, and
recovery is first-run setup rather than debugging. A deployment holding real
revenue reads §15 instead.

The same applies to a devnet reset, which removes the three accounts outright.
`bin/devnet-canary` reports them GONE **only when run against a provisioned
checkout** — the scheduled CI run checks devnet's health and the program's
deployment, and nothing about this site's own accounts.

## Parameters, from `config/site.php`

| | value |
| --- | --- |
| `page_price` | 0.01 DEMO |
| `collection_threshold` | 0.10 DEMO — settles on the tenth view |
| `min_limit` | 0.50 DEMO — fifty views |
| mint | `DEMO`, six decimals, mint authority the faucet key, no freeze authority |
| faucet grant | 0.60 DEMO + 0.05 SOL, once per wallet |
| faucet reserve | 250,000,000 lamports, which funds four visitors |
| `demo_step_views` | 7 — below the threshold, so the advance settles intermittently |
| `grant_ttl_s` | 1,800 — thirty minutes |
| `sweep_every_s` | 300 — with the grant's thirty, the thirty-five the privacy page promises |
| `charge_settle_s` | 120 |
| `close_settle_s` | 180 — longer, because the reader's wallet dialog sits inside its window and the charge's does not |
| `confirm_attempts` / `confirm_spacing_ms` | 6 asks, 2,000 ms apart |

## The program's events

Anchor's convention rather than the runtime's: one `Program data:` log line
carrying `sha256("event:<Name>")[..8]`, then Borsh. Decoded by
`Newsprint\Chain\ProgramEvent`, whose test derives all three rather than
trusting the copy below.

```
Metered { contract: Pubkey, page_views: u32, used: u64, paid: u64, transferred: u64 }   1e8e96a17c2e1d7e
Renewed { contract: Pubkey, limit: u64, carried: u64 }                                  8bfcd923492a0757
Closed  { contract: Pubkey, forgiven: u64 }                                             321f579b87dcc3ef
```
