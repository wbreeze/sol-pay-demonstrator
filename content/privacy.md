---
title: Privacy
slug: privacy
metered: false
status: draft
---

# Privacy

This is the page where a privacy policy would go. It isn't one. A privacy
policy describes what a site collects about you in language broad enough to
cover what it might collect later. This page just lists what this site holds.
The list is short enough to print in full, so it is printed in full.

## Everything this site holds about you

| what | why | how long |
| --- | --- | --- |
| A session cookie — a random id, nothing else | To remember that the wallet you signed in with is yours for this visit | Until you sign out or close the browser |
| Your wallet address, against that session | It is the one thing the payment needs. Every contract is derived from it | The life of the session |
| Which articles you have already paid for, and when | So that a refresh, a back button or a second tab doesn't charge you twice for one article | 30 minutes per article — or the moment you close your meter |
| Your address and IP, if you used the faucet | So one visitor can't drain the demo's tokens | Long enough to enforce the limit |
| Ordinary web server request logs | They are how a web server works | Short, and nobody reads them |

That is the whole list. There is no row for your name, because you were never
asked for it. No row for an email address, a phone number, a card, a billing
address, or an account you have to delete later. No analytics. No advertising
identifier. No third-party anything — this site loads no fonts, no scripts and
no images from a domain it does not control, so no other company learns you
were here.

We also deliberately throw something away. Diagnosing a failed payment means
reading a transaction's logs, and those logs carry your wallet address and your
spending. The code pulls out the error code, discards the rest, and never
writes any of it to a log file or sends it anywhere.

## The row that deserves a second look

Third row. This site keeps a short record of what you have read.

We would rather it did not, and it is there for a reason we will defend: without
it, refreshing a page would charge you for it again. It is a receipt, not a
profile. It answers one question — *has this wallet already paid for this
article?* — and it is never joined up across articles to work out what you like,
never leaves this server, feeds nothing, and is gone in thirty minutes.

We do count how many times each article has been bought. That is a fact about
the article, we would like to know it, and it says nothing about you — the
count survives your receipt being deleted precisely because it never contained
you.

What we do not do is the thing that would make those receipts into a picture of
you: **we never join them up.** No "readers of this also read that", no
suggestions built from what you have opened, no thread drawn between two
articles and labelled with a wallet. That join is the whole of what the
advertising-funded web does with reading histories, and refusing it is most of
what the third row of that table is worth.

There is one more thing we watch, which is particular to running on a public
blockchain: a live per-article counter, next to a public ledger of timestamped
payments, could let an outsider match a payment to a title on a quiet article.
So published counts are coarse. It is a small risk and it is the kind that gets
missed, which is why it is written down.

## When you close your meter, we forget you

Closing your meter ends the arrangement, so it ends the record of it. Your
session, your wallet address and every receipt above are deleted at that moment,
not thirty minutes later, and you are signed out. That is what closing means.

Three honest footnotes, because a promise this clean usually has them and you
should hear them from us.

**It costs you at most one article.** If you close while partway through
something you paid for, it stops being served, because the only way to keep
serving it is to keep the receipt. A penny, and we would rather that than a
promise with an exception in it.

**One record survives, and here is why.** If you took the faucet, we remember
that this wallet took it, or one visitor could close and re-take it forever.
That row does not tell us anything the blockchain does not already say more
permanently: sending you those tokens was itself a public transaction. We are
keeping a copy of something already public, not a secret about you.

**Closing is itself written down, permanently, by closing.** Ending your
contract puts a record on the public chain saying it ended. Your instruction to
be forgotten is the one instruction that cannot be. We would rather you knew
that before you click than discover it afterwards.

## Why the list is that short

Not because we are careful. Because of how you are paying.

A site funded by advertising is not selling you articles. It is selling your
attention, and attention sells for more when the buyer knows whose it is. So
the data follows the business model: attention first, then behaviour, then
identity. The tracking is not a moral failure on any publisher's part. It is
what the job turns into when the only thing you can charge for is the reader.

When you pay directly, that job goes away. There is a product to sell that
isn't you, and the file on you stops earning its keep.

**And the money is not as lopsided as it sounds.** When an advertiser spends a
pound to reach you, industry audits of the programmatic supply chain have twice
found that roughly **half of it never reaches the publisher** — it is absorbed
by the intermediaries in between, the exchanges and platforms and verification
layers you have never heard of and cannot opt out of. Paying a site directly
skips all of them. Your penny arrives as a penny.

We are not going to tell you this beats advertising outright. On a
well-monetized page it does not, and anyone claiming otherwise is hoping you
will not check. What it does beat is what most pages actually earn from the
open ad market, and it reaches the reader who was never going to buy a
subscription — which, across the industry, is about ninety-nine of every
hundred people who arrive.

The trade is explicit and it is not free. You pay money instead. That is the
whole proposition, and a site that pretends otherwise is selling you something
twice.

## Your wallet is not your name. It is also not anonymous.

This site does not know who you are. It never asks, it holds nothing that would
answer the question, and it could not tell anyone if it wanted to.

That is not the same as being anonymous, and you should not let anyone tell you
it is. A wallet address is a pseudonym — a name you did not choose, which is
nevertheless a name, and which the same person tends to keep. Addresses get
linked to people all the time: at the exchange where you bought the tokens,
which knows your identity because the law requires it to; by reusing one
address across services that each know a little; by analysis firms who do this
professionally; sometimes just by timing. None of that involves us. It is also
not something we can protect you from, and a promise we cannot keep is worth
less than the plain description you are reading.

**One part of it is our doing, and you should know about it before you open a
meter.** Your agreement with this site is an account on a public blockchain.
Anyone who knows this site's address and yours can read it — your limit, how
much you have used, how much you have paid — and they can read it years from
now, because nothing on a public ledger is ever taken down. We did not choose
that as a feature and we cannot switch it off; it is what putting a spend meter
on a public ledger means. But it is a record that exists because you used this
site, and pointing at the technology would be a way of not telling you.

So: this site holds almost nothing about you, and in exchange it writes one
permanent public line saying that your wallet paid this site. That is the
actual trade. It is a good one for a demonstration with a worthless token. It
is a real thing to weigh on a site where you would be spending real money and
reading something you would rather not have on the record.

You can close the meter whenever you like. Closing it removes the account and
withdraws the approval your wallet gave. It does not erase the history, because
nobody can.

## If you are building something with this

Do not copy this page as a privacy policy, and do not read it as saying you
won't need one. This is a devnet demonstration with no users, no real money and
nothing to lose, which is why it can afford to be this brief.

A site of yours would be handling a pseudonymous identifier, IP addresses and a
record of what people read. Whether that puts obligations on you, and which
ones, depends on where you are and who your readers are, and it is a question
for your own lawyer rather than for a demo.

What you can take from this page is the shape of it: say what you hold, say why
you hold it, say what you cannot protect people from, and be the one who tells
them about the permanent public record rather than the one who let them find
it.
