---
title: What the limit promises
slug: the-limit
created: 2026-09-22
revised: 2026-10-02
metered: true
status: published
lede: >
  A reader who sets a limit on this site is not setting a reading budget. The
  site signs every charge alone and can charge up to the limit whenever it
  likes. The limit is the most the reader can lose to the site. Three other
  numbers, written once when the site is set up, decide everything else.
reading_time: 6
---

*By Douglas Lovell with Claude Opus 5 (Anthropic)*

The limit looks like a budget. A reader picks a number, reads, and the meter
counts down toward it. A site building on
[sol-pay](https://github.com/wbreeze/sol-pay#what-is-here) should not describe
the limit that way, because its metering program does not treat it that way.

## The site signs every charge alone

A charge is one `meter_and_settle` instruction. The site's server builds that
instruction with the sol-pay client library, signs it with the site authority's
key, and sends the transaction to an RPC endpoint. The reader's wallet does not
sign it and is not asked. That is what lets a reader keep reading with no
interaction after the wallet's one signature at setup, and that is the
argument [What a dime has to do](/a/pay-per-view) makes for why per-view
payment can work at all.

`meter_and_settle` takes a count of page views. On chain, the metering program
multiplies that count by the page price and checks two things: that the meter
has not expired, and that the meter's running total, plus the charge, stays at
or below the limit. Nothing else bounds the count. A site can charge one view,
or fifty, or the whole limit in a single instruction, at any time before the
expiry.

So **the limit is the reader's exposure to the site**, not a pace for the
reader's reading. The honest description on a set-meter screen is "the most
this site can take", and the honest number is small. A site that invites a
large limit is asking for a large amount of trust.

Two other things narrow the exposure, and neither is a budget either. The
expiry bounds the exposure in time: after it, the site can take nothing until
the reader renews. The fund bounds the exposure in money: the site draws from
a fund the reader filled, and a settle for more than the fund holds is
refused. So the most the site can take at any moment is the smaller of two
amounts: what is left under the meter's limit, and what the fund holds.

What keeps a site honest is not the metering program. The metering program
keeps the site within the limit. Beyond that, every charge is public: the
metering program emits a `Metered` event with the page-view count, the running
total and any amount transferred, and anyone can read it on an explorer. A site
that charges fifty views for one article does so in the open.

## Three numbers, written once

A site's pricing lives in its site account on chain. First-run setup sends
the metering program's `initialize_site` instruction, and that instruction
writes three amounts into the account:

| parameter | what it decides | this site |
| --- | --- | --- |
| `item_price` | what one view costs | 0.01 DEMO |
| `collection_threshold` | how much unpaid use builds up before money moves | 0.10 DEMO, ten views |
| `min_limit` | the smallest limit a reader may set | 0.50 DEMO, fifty views |

The metering program enforces two rules at setup. `item_price` must be greater
than zero. `min_limit` must be greater than `collection_threshold`, because a
limit at or below the threshold could never reach its first settle.

**No instruction changes these numbers afterwards.** The site account is
derived from the site authority's key, and the metering program has no
instruction that rewrites a site account. The configuration file this site
reads at setup can be edited later, and the edit changes nothing on chain. The
inspector's *Configuration drift* section exists to show that disagreement
when it happens.

**A new price is a new site account, and readers move to it as their meters
expire.** A site that changes its price makes a new authority key and
initializes a second site account with the new numbers. The first site account
stays where it is. Every meter is opened against one site account, so a meter
opened under the old price goes on being charged the old price, by the old
authority, until that meter expires. A reader's next renewal is a wallet
signature in any case, and that signature opens a meter against the new site
account. Nobody is cut off, and nobody is asked for anything a renewal would
not have asked.

The cost falls on the site. For as long as the longest expiry the site offers,
the server holds two authority keys and meters each reader against the site
account that reader's meter names. That overlap is the reason to keep the
expiries short and to choose the three numbers with care. A price change is
routine. It is also a migration that takes one expiry to finish.

## The price

`item_price` is a per-site decision and nothing in the program constrains it
beyond being more than zero.
This site's 0.01 DEMO was chosen so a visitor reaches a settle within ten
views, not as a recommendation. [What a dime has to do](/a/pay-per-view)
argues for about ten cents.

## The collection threshold

A metering call that leaves the unpaid total below the threshold only raises
the counter. A call that carries the unpaid total to the threshold also
transfers the whole unpaid amount, in the same instruction. The count and the
transfer succeed or fail together.

The usual justification for a threshold is that it saves transactions, and
here that justification is wrong. The transfer rides inside the metering call,
so a site that settled on every view would send exactly as many transactions,
and pay exactly as many fees, as one that settles on every tenth. It saves the
reader no time either: one transaction and one confirmation, either way. What
the threshold buys is three other things.

- **Contention on the treasury.** A settling call writes the site's treasury
  token account. Solana serializes transactions that write the same account,
  so every settle for every reader queues behind every other. A counting call
  writes only that one reader's meter, and the meters are all different.
  With a threshold of ten views, nine calls in ten stay out of that queue.
  Fewer writes to one hot account is also less of the network's scheduling
  capacity spoken for, which is the same argument seen from outside. What
  improves is the site's throughput, at a scale this demonstrator has not
  reached.
- **Where a charge can fail.** A transfer can be refused: a fund that holds
  less than is due, or a frozen account. A call that only counts has nothing
  to refuse. Settling on every view would expose every page view
  to that failure, and would tell the reader about a shortfall at the least
  useful moment.
- **What a mint charges per transfer.** Under Token-2022, a mint can carry a
  transfer fee, or a hook that runs on every transfer. Both are paid per
  transfer, not per view.

What the threshold costs is a float. Between settles the site is owed money it
has not collected, and the threshold is the size of that debt.

- **At close.** Closing forgives whatever is unpaid, and the unpaid total is
  below the threshold by construction. The threshold is the most a site gives
  up when a reader leaves.
- **When the fund runs short.** The fund is the reader's. The reader can
  withdraw from the fund at any time, and another site that meters on the same
  fund can settle first. Either way, the transfer at the next settle is
  refused. Up to a threshold's worth of views can be served before then.
- **In the fund's history.** This one is not a cost to the site. A lower
  threshold means more token transfers out of the reader's fund, and a higher
  one means fewer, larger ones.

So the threshold is a trade, and where to set it is a site's own
arithmetic.

## The minimum limit

The sol-pay README suggests a minimum limit of forty or fifty times the page
price. This site uses fifty. The minimum has to exceed the threshold, and it
should exceed the threshold by enough that a reader sees several settles
before reaching the limit.

The minimum also shapes renewal. Renewing sets a new limit at or above the
minimum, and a new expiry. Renewing also carries any residue that was too
small to collect into the new period, so the new limit must cover that residue
too. The reader's wallet signs the renewal. The new limit is the whole of what
the site may take in the new period, the residue included, because nothing is
paid against the new limit yet.

## One more setting: the token program

A site's mint belongs to one token program: the original SPL Token program or
Token-2022. The mint account's owner says which. Every instruction for that
mint must name the right program, or it fails at runtime. The sol-pay client
library defaults to SPL Token, takes Token-2022 as an option, and offers
`ownsMint` to check the choice against the mint account the site has already
read.

## The rule

**Describe the limit as what it protects against, not as what it allows.** A
reader who understands the limit as a ceiling on loss can choose a number
sensibly. A reader who understands the limit as a budget will be surprised by
the first site that uses the whole of it at once.
