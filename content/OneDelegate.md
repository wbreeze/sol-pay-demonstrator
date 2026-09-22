---
title: The approval that quietly replaces another
slug: one-delegate
created: 2026-09-22
metered: true
status: published
lede: >
  A token account has room for exactly one delegate. When a reader authorizes
  a second site from the same account, the first site's permission is gone,
  and nothing fails until that site next tries to collect. Closing either
  meter can break the other in the same way.
reading_time: 6
---

*By Douglas Lovell with Claude Opus 5 (Anthropic)*

This site meters with
[sol-pay](https://github.com/wbreeze/sol-pay#what-is-here). [The permission
nobody shows you](/a/the-delegate) describes what a reader grants when setting
a limit. The Token program writes two fields onto the reader's token account: a
`delegate`, naming the site's contract account, and a `delegated_amount`, the
most that contract may draw. This piece is about the number of those fields.
There is one of each.

## One delegate, and approve replaces it

A token account's record holds a single `delegate` and a single
`delegated_amount`. The Token program's `approve` does not add a delegate. The
`approve` instruction overwrites the one that is there. That is the Token
program's account layout, not a choice the sol-pay metering program made. The
layout is the same for USDC, and the same again under Token-2022.

Every wallet uses one token account per token by default: the associated token
account, at an address derived from the wallet and the mint. So a reader who
meters two sites in the same token uses the same token account for both,
unless somebody arranges otherwise.

## What happens when a second site is authorized

Suppose a reader has a meter open at site A and then sets a limit at site B,
paying in the same token.

1. B's transaction runs `approve`, naming B's contract account as the
   delegate. The Token program checks the wallet's signature and overwrites
   the field. A's contract account is no longer named anywhere on the token
   account.
2. B's `open_contract` checks that the token account names B's contract as
   delegate, for at least the limit. The check passes.
3. Nothing touches site A. A's contract account still exists on chain, with
   its limit and its running total.

![A reader's token account, owned by the Token program, with the fields mint, owner, amount, delegate and delegated_amount. Its owner field points to the reader's wallet. Its delegate field points to the contract account at site B, labelled written by B's approve. The contract account at site A, which the field no longer names, has a dashed arrow to the token account labelled A's settle is refused, OwnerMismatch.](/assets/img/one-delegate-accounts-light.png)
![A reader's token account, owned by the Token program, with the fields mint, owner, amount, delegate and delegated_amount. Its owner field points to the reader's wallet. Its delegate field points to the contract account at site B, labelled written by B's approve. The contract account at site A, which the field no longer names, has a dashed arrow to the token account labelled A's settle is refused, OwnerMismatch.](/assets/img/one-delegate-accounts-dark.png)

Nothing has failed, and nothing will fail for a while. The metering program
does not check the delegate when it only counts a view. A's next few metering
calls succeed: each one raises `used` and moves no money. The permission is
needed only when the unpaid total reaches the collection threshold and the
metering program asks the Token program to transfer.

At that call the metering program signs as A's contract account. The Token
program finds that A's contract is neither the owner nor the delegate, and
refuses the transfer with its own error `OwnerMismatch`, code 4. The sol-pay
client library describes that code as "wrong owner, or the delegate is no longer set". The
increment and the transfer succeed or fail together, so the whole call fails.
From then on, every metering call for that reader at A carries the unpaid
total past the threshold, and every one fails the same way.

Three things make this failure hard to handle well:

- It lands on the site that did nothing wrong.
- It lands in a transaction the reader did not start and cannot see.
- With this site's numbers, up to nine views accrue at A before the first
  refusal, because the tenth view is the first one that needs the permission.

## Closing breaks the other site too

The same fact runs in reverse. Closing a meter on this site sends
`close_contract` and then `revoke`. The Token program's `revoke` takes the
owner's signature and clears whatever delegate is set. The instruction does
not ask which delegate the caller meant.

So if the reader authorizes B and later closes the old meter at A, A's close
revokes B's permission. B's next settle then fails with `OwnerMismatch`, for
a reader who has just finished leaving somewhere else.

## What an implementer should check

**Compare the delegate with the contract's address, not with nothing.** A
check that asks only whether a delegate is set passes when a different site
holds the permission. The contract account's address is derived from the site
account and the reader's wallet, so every site can compute its own and compare
it with the token account's `delegate` field. The client library's
`diagnose` reports
`delegate_present` as "some delegate is set". That answers the question after
a revoke. That does not answer the question after a second site's approve.

The comparison belongs in three places:

- **Before asking for an approval.** A site's set-meter screen already reads
  the reader's token account to show the balance. If the `delegate` field
  names an address other than this site's contract, the reader is about to end
  an arrangement with another site. The screen should say so before the
  wallet asks.
- **Before building a close.** If the token account no longer names this
  site's contract, send `close_contract` alone. The client library builds
  `closeContract` separately from `closeAndRevoke` for this reason. Revoking
  a permission that belongs to another site is not the reader's intent.
- **When a settle fails.** An `OwnerMismatch` from the Token program means the
  permission is gone. Read the token account. If the delegate is empty, the
  reader revoked it, or the Token program cleared it when the approved amount
  reached zero. If the delegate names another address, another approval
  replaced it. Either way, renewal re-approves. Renewal also repoints the
  delegate, which takes the permission back from whichever site holds it.

None of this changes what the reader can lose. A's contract cannot draw
anything once the permission is gone, and B can draw only up to B's limit.
The cost falls on the sites: a collection that fails, and a residue that goes
uncollected.

## Metering more than one site at once

The metering program already allows it. The program constrains the reader's
token account by two things only: the account's owner must be the reader's
wallet, and its mint must be the site's mint. Nothing requires the associated
token account. A wallet may own any number of token accounts for one mint, so
one token account per site gives one delegate per site. Each site stays
bounded by its own contract's limit.

What stands in the way is not on chain:

- **Wallets.** Wallets show the associated token account. A second account for
  the same token has no ordinary path to create it, fund it, or see its
  balance.
- **Rent.** Each extra token account costs about 0.002 SOL, recovered when the
  account is closed.
- **A split balance.** Balance is per token account, so the reader decides in
  advance how much to leave with each site.
- **Finding the token account.** While every reader uses the associated
  token account, the site derives its address from the wallet and the mint,
  and reads it in the same batch as the other accounts. A site that accepts
  other token accounts cannot derive the address, because `meter_and_settle`
  takes the account as an argument and the contract account does not record
  it. The site can look the account up: one `getTokenAccountsByOwner` call,
  filtered by mint, returns every token account the wallet holds for the
  site's token, and the one whose `delegate` names the site's contract is the
  one to charge. That call is heavier than a read by address, and not every
  endpoint serves it. A site can instead remember the account after the first
  lookup. The remembered address is a cache of a fact on chain: the site reads
  the account on every charge anyway, and a `delegate` that no longer names
  the contract says the cache is stale.

## The rule

**A permission that someone else can replace has to be checked by identity,
not by presence.** A field that holds one value holds the most recent writer's
value. Every site that shares the field should read it as a question — *is
this still mine?* — before relying on it, and before clearing it.
