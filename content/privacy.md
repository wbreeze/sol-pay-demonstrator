---
title: Privacy
slug: privacy
created: 2026-09-04
revised: 2026-10-02
metered: false
status: published
---

This is the page where a privacy policy would go. It isn't one. A privacy
policy describes what a site collects about you in language broad enough to
cover what it might collect later. This page just lists what this site holds.
The list is short enough to print in full; so, here it is in full.

## Everything this site holds about you

| what | why | how long |
| --- | --- | --- |
| A session cookie — a random id, nothing else | To remember which meter this browser reads on | Until you close your meter, or close the browser |
| Your meter and your fund, as they are named on the chain, and the public half of your browser's key, against that session | They are what the payment needs. The meter is what this site counts against, and the key is how your browser shows the meter is its own | The life of the session: at most twelve hours, then deleted within five minutes |
| Which articles you have already paid for, and when, with each payment's transaction id and whether it went through | So that a refresh, a back button or a second tab doesn't charge you twice for one article, and so that an article can be shown before its payment confirms | 30 minutes per article, then deleted within five more — or the moment you close your meter |
| While you set a meter up: the public half of your browser's key, the limit, expiry, deposit and fund number you chose, and your wallet's public key once your wallet asks for the transaction | So that the transaction your wallet signs says what you chose. This is the only time this site is told which wallet is yours | Until you press continue; at most ten minutes |
| A random number, each time your browser proves its key | So that a copied proof cannot be used twice | Until it is used; at most five minutes |
| The wallet you pasted into the faucet form, and when | So one wallet can't take the faucet twice | For good. A footnote below says why |
| A scrambled form of your IP address, if you used the faucet. We cannot turn it back into the address | So one visitor can't drain the demo's tokens | A day |
| A note that you asked to close your meter, if the chain had not confirmed the close yet | So that your records are still deleted when the close lands after we stopped waiting | Until the close is confirmed or found to have failed; at most as long as your session |
| Ordinary web server request logs | They are how a web server works | Short, and nobody reads them |

That is the whole list. There is no row for your name, because you were never
asked for it. No row for an email address, a phone number, a card, a billing
address, or an account you have to delete later. No analytics. No advertising
identifier. No third-party anything. When a page uses a font, a script or a
picture from another company, your browser fetches it from that company. The
fetch tells that company what page you are reading, and in which browser. This
site fetches nothing from anyone else, so those companies learn nothing about
you here.

We also deliberately throw something away. Diagnosing a failed payment means
reading a transaction's logs, and those logs carry your meter and your
spending, one step from your wallet. The code pulls out the error code, discards the rest, and never
writes any of it to a log file or sends it anywhere.

## What stays in your browser

Three things are kept by your browser and not by us.

Your browser holds **a key**, made the first time you set a meter up. The key
is made for this site and for nothing else. Your browser keeps each site's
storage apart, so no other site can read the key, use it, or learn that it
exists, and it cannot be used to follow you from one site to another. This
page's own script can sign with the key and cannot read the secret half out.

Beside the key your browser keeps **which meter is yours**, so that you can
come back tomorrow without your wallet.

And for one tab, when you leave an article to renew your meter, your browser
remembers **which article you came from**, so that it can take you back.

Closing your meter deletes the key and the meter. Closing the tab forgets the
article.

## The row that deserves a second look

Third row. This site keeps a short record of what you have read.

We would rather it did not, and it is there for a reason we will defend: without
it, refreshing a page would charge you for it again. It is a receipt, not a
profile. It answers one question — *has this meter already paid for this
article?* It is never joined up across articles to work out what you like,
never leaves this server, feeds nothing, and is gone within thirty-five minutes.

Each receipt also carries the payment's transaction id and one word for what
became of it. The id is already public on the chain. The word exists because
the article is shown as soon as the payment is sent, before the chain has
confirmed it. The site checks afterwards and writes down what it found. If a
payment never goes through, you keep the article anyway.

We do count how many times each article has been bought. That is a fact about
the article, we would like to know it, and it says nothing about you — the
count survives your receipt being deleted precisely because it never contained
you.

What we do not do is the thing that would make those receipts into a picture of
you: **we never join them up.** No "readers of this also read that", no
suggestions built from what you have opened, no thread drawn between two
articles and labelled with a meter. That join is the whole of what the
advertising-funded web does with reading histories, and refusing it is most of
what the third row of that table is worth.

There is one more thing we watch, which is particular to running on a public
blockchain: a live per-article counter, next to a public ledger of timestamped
payments, could let an outsider match a payment to a title on a quiet article.
So published counts are coarse. It is a small risk and it is the kind that gets
missed, which is why it is written down.

## When you close your meter, we forget you

Closing your meter ends the arrangement, so it ends the record of it. Your
session, our record of your meter and every receipt above are deleted at that
moment, not thirty minutes later, and your browser deletes its key. That is
what closing means.

Three honest footnotes, because a promise this clean usually has them and you
should hear them from us.

**It costs you at most one article.** If you close while partway through
something you paid for, it stops being served, because the only way to keep
serving it is to keep the receipt. A penny, and we would rather that than a
promise with an exception in it.

**One record survives, and here is why.** If you took the faucet, we remember
the wallet you pasted, or one visitor could close and re-take it forever.
That row does not tell us anything the blockchain does not already say more
permanently: sending you those tokens was itself a public transaction. We are
keeping a copy of something already public, not a secret about you.

**Closing is itself written down, permanently, by closing.** Closing your
meter puts a record on the public chain saying it closed. Your instruction to
be forgotten is the one instruction that cannot be. We would rather you knew
that before you click than discover it afterwards.

## Why the list is that short

Not because we are careful. Because of how you are paying.

A site funded by advertising is not selling you articles. It is attracting and
selling your attention. Your attention sells for more when the buyer knows
whose it is. So the data follows the business model: attention first, then
behaviour, then identity.

The profiles come in layers. The ad market keeps one to price your attention.
The publisher keeps its own, to choose what to show you next so that you stay
longer. That profile serves other purposes too. It is the join this page
refuses, a few paragraphs up. Every layer earns its keep the same way: when the
only thing you can charge for is the reader, knowing the reader is the job.

When you pay directly, that job goes away. There is a product to sell that
isn't you, and the file on you stops earning its keep.

**And the money is not as lopsided as it sounds.** When an advertiser spends a
pound to reach you, industry audits of the programmatic supply chain have twice
found that roughly **half of it never reaches the publisher** — it is absorbed
by the intermediaries in between, the exchanges and platforms and verification
layers you have never heard of and cannot opt out of. Paying a site directly
skips all of them. Your payment arrives whole.

We are not going to tell you this beats advertising outright. On a
well-monetized page it does not, and anyone claiming otherwise is hoping you
will not check. Nor is the reader who never subscribes a cheap reader. Almost
nobody arrives unknown to the ad market. That reader's attention is priced on a
profile, like everyone else's.

What a direct payment can do is come near. At about a dime an article, with
about one page view in five paid for, it earns roughly what advertising does,
and it tracks nobody to do it. Nobody has yet measured whether that many
readers will pay. [What a dime has to do](/a/pay-per-view) works through the
numbers.

The trade is explicit and it is not free. You pay money instead. That is the
whole proposition. A site that pretends otherwise is selling you something
twice.

## Your wallet is not your name. It is also not anonymous.

This site does not know who you are. It never asks, it holds nothing that would
answer the question, and it could not tell anyone if it wanted to.

It does not keep your wallet either. The page you read never tells us which
wallet is yours. We are told once, when your wallet asks us for the
transaction that sets your meter up, and we erase it when you press continue.
What we keep is your meter and your fund.

That is not the same as being anonymous, and you should not let anyone tell you
it is. A wallet is known by its public key, and the key is a pseudonym — a name
you did not choose, which is nevertheless a name, and which the same person
tends to keep. Wallets get linked to people all the time: at the exchange where
you bought the tokens, which knows your identity because the law requires it
to; by using one wallet across services that each know a little; by analysis
firms who do this professionally; sometimes just by timing. None of that
involves us. It is also not something we can protect you from, and a promise we
cannot keep is worth less than the plain description you are reading.

**One part of it is our doing, and you should know about it before you open a
meter.** Your meter is an account on a public blockchain. It names your fund,
and your fund names your wallet. Anyone who knows your wallet can find your
funds, and from them every site's meter that draws on them — your limit, how
much you have used, how much you have paid — and they can read it years from
now, because nothing on a public ledger is ever taken down. We did not choose
that as a feature and we cannot switch it off; it is what putting a spend meter
on a public ledger means. It is a record that exists because you used this
site. Pointing at the technology would be a way of not telling you.

So: this site holds almost nothing about you, and in exchange it writes one
permanent public line saying that your fund paid this site, and your fund says
whose it is. That is the actual trade. It is a good one for a demonstration
with a worthless token. It is a real thing to weigh on a site where you would
be spending real money and reading something you would rather not have on the
record. A second fund keeps two sites from seeing that they share a reader. It
does not hide either fund from someone who already knows your wallet.

**Your wallet also talks to us directly, once.** To set a meter up, your
wallet fetches the transaction from this site. So this site sees the IP
address of the device your wallet runs on, as any web server sees its
visitors. That lands in the request logs in the table above, and is not
recorded against your wallet. Where your wallet then sends the transaction is
between you and your wallet's maker, and we do not see it.

You can close the meter whenever you like. Closing it removes the meter. Your
fund stays, with whatever it holds, and is yours to withdraw from. Closing
does not erase the history, because nobody can.

## If you are building something with this

Do not copy this page as a privacy policy. Do not read it as saying you
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

