---
title: How to read the inspector
slug: reading-the-inspector
created: 2026-09-14
revised: 2026-10-04
metered: false
status: published
lede: >
  The panel at the foot of every page has up to ten sections, and each one
  answers a different question about where a number came from. Here is what
  each of them says, and what this site had to do to be able to say it.
reading_time: 10
---

*By Douglas Lovell with Claude Opus 5 (Anthropic)*

Every page of this demonstrator ends with an inspector.

![The foot of a page: a hairline rule, and the word INSPECTOR beside a disclosure triangle.](/assets/img/inspector-closed-light.png)
![The foot of a page: a hairline rule, and the word INSPECTOR beside a disclosure triangle.](/assets/img/inspector-closed-dark.png)

Expanding the inspector shows every number behind the metering of this page,
each one read from an account on the public chain. The inspector exists for a
technical person evaluating the
[sol-pay](https://github.com/wbreeze/sol-pay#what-is-here) on-chain metering capability
that this site demonstrates.

## The order

The inspector displays the most-changing data above the more static data.

- The values, in full: the key to every short name below. Above the gradient,
  changed or not.
- Preflight: changes on every request. The site's own arithmetic over the
  accounts read for this request.
- The last transaction: present only on a request that sent one. The
  instruction as the sol-pay client library built it, not as the chain
  returned it.
- Your meter, on chain: changes when the reader is charged. This site's count
  against the reader's fund.
- Your fund, on chain: changes when a settle draws on it, or when the reader
  adds to it. The reader's money, which other sites may meter on too.
- Your wallet: seldom changes. The wallet that the fund names.
- The treasury: changes when a settle lands. The on-chain account of the
  site's income from readership.
- Configuration drift: shown if configuration diverges from the site account.
- Site account: read from the chain on this request. First-run setup wrote
  it, and nothing writes it again.
- Deployment: never changes.

The three sections about the reader appear only for a browser that holds a
meter.

## The values, in full

This is mostly an address table. Base58 is unreadable, and worse than
unreadable it is *comparable-looking*: two addresses that share four leading
characters read as the same address to an eye scanning a panel. So every long
value the panel shows gets a short name — a role prefix and a nonsense
syllable.  This table is where the short name is introduced beside the thing it
stands for.

![A table of eleven rows. Each row: a short name like MPDAvim, the full base58 address with a copy button after it, and a line beneath naming what the value is and then the derivation.](/assets/img/inspector-names-light.png)
![A table of eleven rows. Each row: a short name like MPDAvim, the full base58 address with a copy button after it, and a line beneath naming what the value is and then the derivation.](/assets/img/inspector-names-dark.png)

The short names are this site's invention and mean nothing to a wallet or an
explorer.  **The full base58 is always one click away and always what gets
copied.**

Everywhere else in the inspector, a long value is written as its short name
alone, linked to its row here.

### The explanations

Under each value is a description of what it represents and how it is
derived.

Three of these addresses are derived by the sol-pay metering program. The
site account comes from `["site", AUTH…]`. The reader's fund comes from
`["fund", RDR…, MINT…, index]`. The meter comes from
`["meter", SPDA…, FPDA…]`. Each has the bump that made it land off the curve.
The seeds are written in short names, so every seed names another row of this
table.

Two more are derived by Solana's associated-token program, which this site
did not write and does not own: the treasury and the fund's token account.

The rest were never derived, for different reasons. The mint and the site
authority are keypairs that first-run setup generated. The browser's key was
generated in the reader's browser. The reader's wallet is the reader's own.
The metering program has the address it was deployed to. The token program's
address is a constant that every Solana cluster shares.

## Preflight, for this request

Displays five answers the site works out for itself before it sends anything,
each with the on-chain program check the answer mirrors written underneath.

![Five rows: charge(1), can_meter, will_settle, items_remaining and limit_floor, each with its answer and, beneath it, the on-chain check it mirrors.](/assets/img/inspector-preflight-light.png)
![Five rows: charge(1), can_meter, will_settle, items_remaining and limit_floor, each with its answer and, beneath it, the on-chain check it mirrors.](/assets/img/inspector-preflight-dark.png)

The preflight saves the server a round trip. The endpoint the server queries
will simulate a transaction before forwarding it. A call the
on-chain program would refuse is usually rejected there, never included in a
block, and costs nothing.

A fee is charged when a transaction lands and then fails. Failure implies that
the accounts moved between the simulation and the call: another site's
settle drew down the same fund, or the reader's wallet withdrew from it, in
the gap.
The fee is the site's fee either way. The site authority signs every metering
call and pays for it. The site pays for the close too. The only transactions
the reader pays for are the ones the reader's wallet signs: the setup, a
renewal and a deposit.

The mirroring is the point, and it is also the risk. The client library's
arithmetic is a *copy* of the program's, made because a web server cannot call
into a Solana program to ask. A copy can drift. The library's own
documentation says so and names the conformance run that pins it.
This section adds the other direction: a reader who trusts neither can take
these five answers, compare them against the account fields,
and do the arithmetic themselves.

**These are predictions, not decisions.** The program checks every one of them
again. The on-chain program's answer is the answer that charges. Where the two
disagree, the program is right and the server view has diverged.

One of the five can disagree honestly. `can_meter` asks whether the meter has
expired by this server's clock, and the program reads the cluster's clock.
Near the expiry the two answers differ by however far apart the clocks are.

After a close there is no meter. Three of the five have nothing to answer
against, and they say so.

## The last transaction

The transaction this request sent, and the instruction that went into it.

![Signature, outcome, items, then the instruction: program, eight accounts in order with their signer and writable flags, and the data. Then the decoded event, and a link reading This transaction on chain.](/assets/img/inspector-last-transaction-light.png)
![Signature, outcome, items, then the instruction: program, eight accounts in order with their signer and writable flags, and the data. Then the decoded event, and a link reading This transaction on chain.](/assets/img/inspector-last-transaction-dark.png)

A charge is one instruction of the metering program, `meter_and_settle`.
This site's web server does not assemble that instruction by hand. The web
server calls the sol-pay client library, the PHP package that knows the
metering program's account order and byte layout, and the client library
returns the instruction. These rows show what the client library returned:
the program, the accounts in order with their signer and writable flags, and
the data bytes.  The first eight data bytes are the Anchor discriminator,
sha256("global:meter_and_settle") truncated. The rest is borsh.

The web server puts the instruction into a transaction without changing it.
That is what makes this section a check on the client library, not a
description of it.

The site serves an article as soon as the endpoint accepts the charge, and a
later request asks whether the charge landed.
[Two orderings that disagree on purpose](/a/two-orderings) explains why. Only
the request that built the instruction ever holds it. So the page keeps the
instruction rows in place when the later answer arrives, and the outcome row
changes above them.

**"Last" means this request's. That is a consequence of what the site
refuses to keep.** A charge's signature is kept with the grant that the
charge bought, for about half an hour, so that a later request can ask
whether the charge landed. Beyond that there is no list of a meter's calls
anywhere in this system, because a list like that is exactly the reading
history the design exists not to hold. On a page that sent nothing the
section is absent, not empty.

### The close

Closing the meter is the other transaction this site sends. The browser's key
signs the close and the site authority pays the fee.

![Signature, an outcome reading closed, the meter account is gone, a row saying where the instruction rows came from, then the instruction: program, five accounts with their flags, and the data. Then the decoded Closed event.](/assets/img/inspector-close-light.png)
![Signature, an outcome reading closed, the meter account is gone, a row saying where the instruction rows came from, then the instruction: program, five accounts with their flags, and the data. Then the decoded Closed event.](/assets/img/inspector-close-dark.png)

The outcome row reports an account, not a signature. After the site sends the
close, the site reads the meter's address. The meter being gone is the
evidence that the close landed. That one read also fetches the fund and the
site's accounts. So the panel shows the accounts as the close left them, and
the close pays for no extra round trip.

The instruction rows have a different source here, and a row says so. One
request composes the close for the browser's key to sign. The next request
sends the signed close. The compiled message is all the site keeps between
the two requests. So the rows are read back out of the message that the key
signed. Building the instruction a second time for display would prove
nothing: a panel that rebuilt the instruction would agree with itself whatever
had been sent.

### What the wallet sends is not here

The setup, a renewal and a deposit are the reader's transactions. The site
composes each one when the wallet asks for it, and the wallet signs and
submits it. The site sends none of them, so none of them is ever *the last
transaction*. *Your wallet*, below, is where the panel says that one has
happened.

### The row that fills in when the inspector opens

The `event` row arrives saying it has not been read yet, and then reads
itself.

Decoding the program's own `Metered`, `Renewed` or `Closed` event needs a
`getTransaction` call. That would be one more round trip on every charge.
Rather than spend it on every metered view, the panel asks a small endpoint
for it when the inspector is expanded, and once.  The answer cannot change for
a landed signature.

Without JavaScript the row keeps the sentence the server put there, which
stays true rather than becoming a spinner that never resolves.

The small endpoint holds no session and nothing that is privileged. The
transaction signature is public. Every byte it returns is readable by anyone
holding the same signature and an explorer. It is not a history lookup.  It
answers about the one signature it is handed.

## Your meter, on chain

This site's count against the reader's fund, read back from the chain.

![The meter and its key as short names, then expiry, limit, used, paid, unpaid and bump — each amount in DEMO and in base units.](/assets/img/inspector-meter-light.png)
![The meter and its key as short names, then expiry, limit, used, paid, unpaid and bump — each amount in DEMO and in base units.](/assets/img/inspector-meter-dark.png)

**The key and the expiry are the rows that matter.** The key says which
browser the meter answers to. The site reads the meter on every request, and
a meter that names another key ends this browser's session. A renewal from
another device does exactly that. [The ID of what's paying](/a/no-sign-in-page)
explains why a key in the browser stands where a sign-in page would.

The expiry bounds what a meter left behind on a machine can cost, whatever
happens to the machine. After the expiry the program refuses every charge.
[What the limit promises](/a/the-limit) covers the limit and the expiry
together.

Below them: the limit set, how much has been used against it, how much has
actually been transferred, and the difference.

## Your fund, on chain

The reader's money, and the account that holds it.

![The fund as a short name, then its reader, mint, index, the number of meters open on it and its bump. Then the fund's token account as a short name, and its balance in DEMO and in base units.](/assets/img/inspector-fund-light.png)
![The fund as a short name, then its reader, mint, index, the number of meters open on it and its bump. Then the fund's token account as a short name, and its balance in DEMO and in base units.](/assets/img/inspector-fund-dark.png)

The meter and the fund are two sections because they are two accounts. The
meter is this site's. The fund is the reader's, and any site that uses the
metering program can open a meter on it. `meters open` counts those meters
across every such site. It is the one figure in these sections that is not
about this site.

The balance is not a field of the fund. The money sits in the fund's token
account, an associated token account that the fund itself owns. A settle
draws on that account, and the metering program signs the transfer with the
fund's seeds. So the address to open in an explorer for the balance is the
token account's, `FATA` in the values table.

## Your wallet

The wallet that the fund names, and what that wallet last did.

![One row: the wallet as a short name, with a line beneath reading named by your fund; this page was never told it. A second row labelled sent reads a renewal of this meter, before this page loaded.](/assets/img/inspector-wallet-light.png)
![One row: the wallet as a short name, with a line beneath reading named by your fund; this page was never told it. A second row labelled sent reads a renewal of this meter, before this page loaded.](/assets/img/inspector-wallet-dark.png)

The heading does not say *on chain*, and the omission is deliberate. The site
reads nothing from the wallet's own account. The page never asks which wallet
the reader holds. The fund records its reader, and the fund is public, so the
panel can show the wallet and say where the panel learned it.

The `sent` row is the only row in the inspector that the server does not
know. The wallet submits its own transaction. No request the site serves
afterwards can tell that a setup, a renewal or a deposit has just gone. The
browser can tell, because the reader pressed *continue* there. The browser
carries one word to the next page, and the panel shows the matching row. The
server wrote the row's words into every panel, in an element that displays
nothing until the browser picks a row. The server is not told which row was
picked, and stores nothing.

The row lasts one page. A reload loses the row, as a reload loses a charge's
instruction rows. No signature is shown, because the wallet sent the
transaction and the site did not see it. The result is in the two sections
above: a new expiry on the meter, or a larger balance in the fund.

## Treasury

What readers have paid so far, on this deployment.

![Treasury token account, balance and owner.](/assets/img/inspector-treasury-light.png)
![Treasury token account, balance and owner.](/assets/img/inspector-treasury-dark.png)

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

![The site account's fields in order: site account, authority, mint, treasury, item price, collection threshold, minimum limit, bump and mint decimals.](/assets/img/inspector-site-account-light.png)
![The site account's fields in order: site account, authority, mint, treasury, item price, collection threshold, minimum limit, bump and mint decimals.](/assets/img/inspector-site-account-dark.png)

Read from the account on this request.  The fields are in the order and sizes
the client library's specification lays them out in (wasm-client/SPEC.md §6.2).
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
would learn an IP address to match with a meter, on every page view.
This is precisely the profile this site is built not to produce.

Seeing only this server, the provider learns only that one server asked about
some accounts.

A copy run for development adds a row here when the development wallet is on.
That wallet signs a setup in place of a phone, so the row is there to keep
anything done with it from being mistaken for a reader's wallet.

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
page included — would block on an account read to fill a panel that is
collapsed by default. On the privacy page that one call *was* the page load:
0.899 seconds, on a page that displays nothing from the chain at all. Without
it, the page load is two milliseconds.  The rule is not *defer the
panel*. It is **defer the read nothing else needs**.

Where a request has already read the chain for its own
reasons — the article's POST, where the metering decision cannot be made
without reading — the panel renders inline with the answer already in hand. It
costs nothing extra, because the read was required work either way.

Rendering the panel from the POST's own read keeps *The last transaction*
possible. That section needs a result that exists only on the request that
produced it, and the result would not survive a deferred fetch. Under this
rule it never has to. The close follows the same rule: the request that sends
the close renders the panel for it.

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
