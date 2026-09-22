---
title: How to read the inspector
slug: reading-the-inspector
created: 2026-09-14
revised: 2026-09-22
metered: false
status: published
lede: >
  The panel at the foot of every page has up to eight sections, and each one
  answers a different question about where a number came from. Here is what
  each of them says, and what this site had to do to be able to say it.
reading_time: 9
---

*By Douglas Lovell with Claude Opus 5 (Anthropic)*

Every page of this demonstrator ends with an inspector.

![The foot of a page: a hairline rule, and the word INSPECTOR beside a disclosure triangle.](/assets/img/inspector-closed-light.png)
![The foot of a page: a hairline rule, and the word INSPECTOR beside a disclosure triangle.](/assets/img/inspector-closed-dark.png)

Expanding the inspector shows every number behind the metering of this page,
each one read from an account on the public chain. The inspector exists for a
technical person evaluating the
[`sol-pay`](https://github.com/wbreeze/sol-pay) on-chain metering capability
that this site demonstrates.

## The order

The inspector displays the most-changing data above the more static data.

- The values, in full: the key to every short name below. Above the gradient,
  changed or not.
- Preflight: changes on every request. The site's own arithmetic over the
  accounts read for this request.
- The last transaction: present only on a request that metered. The
  instruction as the sol-pay library built it, not as the chain returned it.
- You, on chain: read on every request that has a wallet. Changes when the
  reader is charged. The on-chain content that links the reader to the meter.
- The treasury: changes when a settle lands. The on-chain account of the
  site's income from readership.
- Configuration drift: shown if configuration diverges from the site account.
- Site account: read from the chain on this request. First-run setup wrote
  it, and nothing writes it again.
- Deployment: never changes.

The system assembles the panel in reverse, outward from the deployment —
program, then site, then treasury, then the reader, then the request.

## The values, in full

This is mostly an address table. Base58 is unreadable, and worse than
unreadable it is *comparable-looking*: two addresses that share four leading
characters read as the same address to an eye scanning a panel. So every long
value the panel shows gets a short name — a role prefix and a nonsense
syllable.  This table is where the short name is introduced beside the thing it
stands for.

![A table of ten rows. Each row: a short name like SPDAyap, the full base58 address with a copy button after it, and a line beneath naming what the value is and then the derivation.](/assets/img/inspector-names-light.png)
![A table of ten rows. Each row: a short name like SPDAyap, the full base58 address with a copy button after it, and a line beneath naming what the value is and then the derivation.](/assets/img/inspector-names-dark.png)

The short names are this site's invention and mean nothing to a wallet or an
explorer.  **The full base58 is always one click away and always what gets
copied.**

Everywhere else in the inspector, a long value is written as its short name
alone, linked to its row here.

### The explanations

Under each value is a description of what it represents and how it is
derived.

Some of these addresses are derived by the metering program: the site
account from `["site", AUTH…]` and the reader's contract account from
`["contract", SPDA…, PAYR…]`, each with the bump that made it land off the
curve. Two more are derived by Solana's associated-token program, which this
site did not write and does not own — the treasury and the reader's token
account. Five were never derived at all, for four different reasons: two are
keypairs first-run setup generated, one is the reader's own wallet, one is an
address a program was deployed to, and one is a constant every Solana cluster
shares.

## Preflight, for this request

Displays six answers the site works out for itself before it sends anything,
each with the on-chain program check the answer mirrors written underneath.

![Six rows: charge(1), can_meter, will_settle, views_remaining, limit_floor and required_allowance, each with its answer and, beneath it, the on-chain check it mirrors.](/assets/img/inspector-preflight-light.png)
![Six rows: charge(1), can_meter, will_settle, views_remaining, limit_floor and required_allowance, each with its answer and, beneath it, the on-chain check it mirrors.](/assets/img/inspector-preflight-dark.png)

The preflight saves the server a round trip. The endpoint the server queries
will simulate a transaction before forwarding it. A call the
on-chain program would refuse is usually rejected there, never included in a
block, and costs nothing.

A fee is charged when a transaction lands and then fails. Failure implies that
the accounts moved between the simulation and call: another copy of this site
metered the same wallet, or the wallet had an unrelated transaction in the gap.
The fee is the site's fee either way. The site authority signs every metering
call and pays for it. The only transactions the reader pays for are the three the
reader's wallet signs.

The mirroring is the point, and it is also the risk. The client library's
arithmetic is a *copy* of the program's, made because a web server cannot call
into a Solana program to ask. A copy can drift. The library's own
documentation says so and names the conformance run that pins it.
This section adds the other direction: a reader who trusts neither can take
these six answers, compare them against the account fields,
and do the arithmetic themselves.

**These are predictions, not decisions.** The program checks every one of them
again. The on-chain program's answer is the answer that charges. Where the two
disagree, the program is right and the server view has diverged.

When there is no contract yet, three of the six have nothing to answer
against, and they say so.

## The last transaction

The transaction this request produced, and the instruction that went into it.

![Signature, outcome, page views, then the instruction: program, eight accounts in order with their signer and writable flags, and the data. Below, a link reading This transaction on chain.](/assets/img/inspector-last-transaction-light.png)
![Signature, outcome, page views, then the instruction: program, eight accounts in order with their signer and writable flags, and the data. Below, a link reading This transaction on chain.](/assets/img/inspector-last-transaction-dark.png)

The objects the sol-pay library handed the server— the program, the accounts in
order with their signer and writable flags, and the data bytes.  The first
eight data bytes are the Anchor discriminator,
sha256("global:meter_and_settle") truncated; the rest is borsh. The account
order, the signer and writable flags are the builder's, unedited, which is what
makes this a check on the library rather than a description of it.

The three transactions built in the browser show less here and
say so. Opening a contract, renewing one and closing one are compiled by the
wasm client in the browser. The server never held those instruction
objects: their signature and their decoded event appear, their bytes do not.
A line names who built them.

**"Last" means this request's. That is a consequence of what the site
refuses to keep.** There is no per-wallet list of metering calls anywhere in
this system, because a list like that is exactly the reading history the
design exists not to hold. On a page that metered nothing the section is
absent rather than empty.

### The row that fills in when the inspector opens

The `event` row arrives saying it has not been read yet, and then reads
itself.

Decoding the program's own `Metered`, `Renewed` or `Closed` event needs a
`getTransaction` call. That would be a fourth round trip on a request that is
budgeted about three. Rather than spend it on every metered view, the panel
asks a small endpoint for it when expanding the inspector, and once.  The
answer cannot change for a landed signature.

Without JavaScript the row keeps the sentence the server put there, which
stays true rather than becoming a spinner that never resolves.

The small endpoint holds no session and nothing that is privileged. The
transaction signature is public. Every byte it returns is readable by anyone
holding the same signature and an explorer. It is not a history lookup.  It
answers about the one signature it is handed.

## You, on chain

The reader's accounts, read back from the chain on every request that has a
wallet to read for.

![Your wallet and token account as short names, then balance, delegate, approved, and the contract's limit, used, paid and unpaid — each amount in DEMO and in base units.](/assets/img/inspector-you-light.png)
![Your wallet and token account as short names, then balance, delegate, approved, and the contract's limit, used, paid and unpaid — each amount in DEMO and in base units.](/assets/img/inspector-you-dark.png)

Authorizing a limit names this site's contract account as a delegate on the
reader's token account and sets how much it may draw.  Closing clears both.
**The delegate line is what authorizing gave this site and what closing takes
back.** The delegate lives on the reader's token account, not in the contract
account. [The permission nobody shows you](/a/the-delegate) explains how the
Token program writes it. Most
wallets never show it. It is here, read back from the account on every
request. The delegation is a field on the token account, so the address to
open in an explorer is the token account's — `PATA` in the values table — and
not the delegate's.

This represents the entire content the reader gives this site and the entire
content of what the reader takes back.

Below it, the contract: the limit set, how much has been used against it,
how much has actually been transferred, and the difference.

With no approved contract, the address is still shown. It is derived, so it
exists as an address whether or not there is an account with it. (The row below
says exactly that.) The explorer link is dropped.

## Treasury

What readers have paid so far, on this deployment.

![Treasury token account, balance, owner and delegate.](/assets/img/inspector-treasury-light.png)
![Treasury token account, balance, owner and delegate.](/assets/img/inspector-treasury-dark.png)

The treasury is a derived address rather than something setup created. It is
the site authority's associated token account for the mint. The provisioner
computes the address before anything exists there. That is an easy one to get
wrong. The TRSY line in the values table names the program that derives it.

## Configuration drift

This section is an alarm that seldom sounds.

![Two rows, each reading config says one number and the chain says another.](/assets/img/inspector-drift-light.png)
![Two rows, each reading config says one number and the chain says another.](/assets/img/inspector-drift-dark.png)

The site's prices live in a config file. That config decides what first-run setup
writes on chain. After that the chain is the authority and nothing reads the
config for a price again. Editing the config file afterwards changes
nothing. The site is initialised once. The only visible consequence
will be a source file that disagrees with the running site.

When the two disagree, this section appears and says so, in both numbers. It
sits directly above the account it is disagreeing with, so the claim can be
checked one section later.

## Site account, decoded

The account this site runs on, field by field.

![The site account's fields in order: site account, authority, mint, treasury, page price, collection threshold, minimum limit, bump and mint decimals.](/assets/img/inspector-site-account-light.png)
![The site account's fields in order: site account, authority, mint, treasury, page price, collection threshold, minimum limit, bump and mint decimals.](/assets/img/inspector-site-account-dark.png)

Read from the account on this request.  The fields are in the order and sizes
the sol-pay library specification lays them out in (wasm-client/SPEC.md §6.2).
It is itself a check, since a field read at the wrong offset would show up here
as a number that makes no sense, rather than as silence.

Every amount appears twice, as a decimal figure and as base units.  A
six-decimal scaling error — turning 50 into 50,000,000, or the reverse — is
invisible until the two forms sit side by side. The library specification
explicitly warns about it. The decimal figure is computed at the decimals the
mint itself reports, not at the decimals the config assumes.

The collection threshold and the minimum limit also say what they are in
views, because a threshold quoted only in tokens is a number nobody can act
on.

## Deployment

Four things. They never change.

![Metering program and token program as short names, then cluster devnet and the RPC endpoint.](/assets/img/inspector-deployment-light.png)
![Metering program and token program as short names, then cluster devnet and the RPC endpoint.](/assets/img/inspector-deployment-dark.png)

- which program does the metering
- which token program it uses
- which Solana cluster this is running
- which RPC endpoint this calls.

The endpoint is on screen for a reason that is easy to miss. **It is called by
this server and never by the browser.** An RPC provider that saw the browser
would learn an IP address to match with a wallet address, on every page view.
This is precisely the profile this site is built not to produce.

Seeing only this server, the provider learns only that one server asked about
some accounts.

## When the panel has nothing to show yet

On a page that had no reason to touch the chain, the panel opens onto a
sentence and a link instead of onto sections.

![A paragraph reading: This page needed nothing from the chain, so it read nothing. The inspector reads the accounts when you open it — read them now.](/assets/img/inspector-deferred-light.png)
![A paragraph reading: This page needed nothing from the chain, so it read nothing. The inspector reads the accounts when you open it — read them now.](/assets/img/inspector-deferred-dark.png)

## The cold call

Here is something this implementation measured as well as reasoned. Filling
the inspector costs one `getMultipleAccounts` call to read the accounts it
displays.

The inspection panel is rendered on every page, so every page — the privacy
page included — blocked on an account read to fill a panel that is collapsed by
default. On the privacy page that one call *was* the page load: 0.899 seconds,
on a page that displays nothing from the chain at all. Without it, the page
load is two milliseconds.  The rule that came out of this is not *defer the
panel*. It is **defer the read nothing else needs**.

Where a request has already read the chain for its own
reasons — the article's POST, where the metering decision cannot be made
without reading — the panel renders inline with the answer already in hand. It
costs nothing extra, because the read was required work either way.

Rendering the server read result of the POST keeps *The last transaction*
possible. It needs a result that exists only on the request that produced it.
The result of the POST would not survive a deferred fetch. Under this rule it
never has to.

**The link in the deferred panel's paragraph is not decoration.** Without JavaScript it *is*
the panel: it goes to a page that renders the same sections server-side, from
the same partial, so the fragment and the page cannot drift apart. Nothing
about the inspector depends on a script to stay reachable.

Two other states are worth naming. When the chain cannot be read, the panel
says so and the page is still served — nothing on this site depends on the
chain being reachable until a charge has to be made. On a copy that has
not been set up, the panel says what is missing and shows the configured
prices instead, which are the only prices there are until something is written
on chain.

## What the panel is for, one more time

Every section above is the same argument in a different place: a number
displayed without its source is a number the reader has to take on trust, and
this site is trying not to be trusted.

