---
title: Privacy
slug: privacy
created: 2026-09-04
revised: 2026-10-03
metered: false
status: published
---

This is the page where a privacy policy would go. It isn't one. A privacy
policy is a disclosure of data collection and use, shaped around laws, in
language broad enough to cover expansion of collection and use.  This page simply lists
what this site holds.  The list is short enough to print in full; so, here you
will find it in full.

## Why the list is short

Not because we are careful. Because of how you are paying.

A site funded by advertising is not selling you articles. It is attracting and
selling your attention. Your attention sells for more when the buyer knows
whose it is. So the data follows the business model: attention first, then
behaviour, then identity.

The profiles come in layers. The ad market keeps one to price your attention.
The publisher keeps its own, to choose what to show you next so that you stay
longer. That profile serves other purposes too.  Every layer earns its keep the
same way: when the only thing you can charge for is the reader, knowing the
reader is the job.

This site does not assemble profiles.  When you pay directly, that job goes
away. There is a product to sell that isn't you, and the file on you stops
earning its keep.

## The economics

**The money is not as lopsided as it sounds.** When an advertiser spends a
dollar to reach you, industry audits of the programmatic supply chain have twice
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

## Everything this site holds about you

Here is the complete list of *what is collected and/or stored*, for what
purpose and how long:

- *A session cookie* — a random id, nothing else, to remember which meter this
  browser reads on, until you close your meter, or close the browser.
- *On chain account addresses* for your meter and your fund and the public half of your
  browser's proof key, all identified to the session cookie, for payment.  The
  meter is what this site counts against. The proof key is how your browser
  proves the meter is yours. These survive for the life of the session: at most
  twelve hours, then deleted within five minutes.
- *Which articles you have paid for,* and when, with each payment's transaction
  id and whether it went through, so that a refresh, a back button or a second
  tab do not charge you twice for the same article and so that an article can
  be shown before its payment confirms. The site deletes these within five
  minutes after a reading allowance of thirty minutes and immediately when you
  close your meter.
- *A random number* each time your browser proves its key, so that a proof
  cannot be copied and used more than once, until it is used, at most five
  minutes.
- *A note that your browser asked to close your meter,* if the chain had not
  confirmed the close yet, to remember to delete your records when the close
  lands, after we stopped waiting, until the close is confirmed or found to
  have failed, at most as long as your session.

### During meter setup or renewal

- *Funding information* — the public half of your browser's proof key, the limit,
  expiry, deposit and fund number you chose, and your wallet's public key once
  your wallet asks for the transaction, so that the transaction your wallet
  signs says what you chose. This is the only time this site can determine which
  wallet is yours, when you setup or renew a meter, until you press continue,
  at most ten minutes.

### When you use the faucet

- *The wallet you pasted into the faucet form, and when,* so one wallet can't
  take the faucet twice, for the life of the site. A footnote below says why.
- *A hash of your IP address.* The site could only reverse it back into the
  address by guessing or exhaustive search and does not do so. The hash
  prevents one visitor from draining the demo's tokens.  The site keeps it one
  day.
- *The transfer transaction ID*, a value from the publicly viewable chain.

That is the whole list.  There is no record of your name, because you were never
asked for it. No record of an email address, phone number, payment card data, a
billing address, no account that you have to delete later. No analytics. No
advertising identifier. No third-party anything. When a page uses a font, a
script or a picture from another company, your browser fetches it from that
company. The fetch tells that company what page you are reading, and in which
browser. This site fetches nothing from anyone else, so those companies learn
nothing about you here.

The site also deliberately throws something away. Diagnosing a failed payment
means reading the logs from a transaction. Those logs carry your meter and your
spending, one step from your wallet. The site extracts which program refused
and its error code. It discards the rest. On a successful charge it extracts
the meter information to display in the inspector. It never writes any of that
data to a log file nor sends it anywhere but back to you.

## What stays in your browser

Three things are kept by your browser, not on the site server.

Your browser holds **a key**, made the first time you set a meter up. The key
is made for this site and for nothing else. Your browser keeps each site's
storage apart, so no other site can read the key, use it, or learn that it
exists. It cannot be used to follow you from one site to another. This
page's own script can sign with the key and cannot read the secret half.

Beside the key your browser keeps **which meter is yours**, so that you can
come back tomorrow without your wallet.

And for one tab, when you leave an article to renew your meter, your browser
remembers **which article you came from**, so the site can take you back.

Closing your meter deletes the key and the meter. Closing the tab forgets the
article.

## The record that deserves a second look

This site keeps a brief record of what you have read.

We would rather it did not, and it is there for a reason we will defend:
without it, refreshing a page would charge you for it again. It is a receipt,
not a profile. It answers one question — *has this meter already paid for this
article?* It is never joined up across articles to work out what you like,
never leaves the site server, feeds nothing, and is gone within thirty-five
minutes.

Each receipt also carries the payment's transaction id and one word for what
became of it. The id is already public on the chain. The word exists because
the article is shown as soon as the payment is sent, before the chain has
confirmed it. The site checks afterwards and writes down what it found. If a
payment never goes through, you keep the article anyway.

We do count how many times each article has been bought. That is a fact about
the article. It says nothing about you. The count survives your receipt being
deleted precisely because it never contained you.

What we do not do is the thing that would make the receipts into a picture of
you: **we never join them up.** No "readers of this also read that," no
suggestions built from what you have opened, no thread drawn between two
articles and labelled with a meter. That join is the whole of what the
advertising-funded web does with reading histories, and refusing it is most of
what the brief record of reading is worth.

There is one more thing we would manage. It is particular to running on a
public blockchain. A live count of reads correlated with the public ledger of
timestamped payments, on an article viewed infrequently, could let an outsider
match a payment to an article. For this reason, any published counts would
update with coarse frequency. It is a small risk of the kind that is easily
missed.  That is why we mention it here.

## When you close your meter, we forget you

Closing your meter ends the arrangement, so it ends the record of it. Your
session, our record of your meter and every article receipt is deleted at that
moment, not thirty minutes later, and your browser deletes its key. That is
what closing means.

Three honest footnotes, because a promise this clean usually has them and you
should hear them from us.

**It removes any article grants you have.** If you close while partway through
something you paid for, it stops being served, because the only way to keep
serving it is to keep the receipt. A penny each, and we would rather that than a
promise with an exception in it.

**One record survives, and here is why.** If you took the faucet, we remember
the wallet you pasted. Otherwise, one visitor could re-take the grant
indefinitely. The wallet record does not tell us anything the blockchain does
not already say more permanently: sending you those tokens was itself a public
transaction. We are keeping a copy of something already public, not a secret
about you.

**Closing is itself written down, permanently, by closing.** Closing your
meter puts a record on the public chain saying it closed. Your instruction to
be forgotten is the one instruction that cannot be. We would rather you knew
that before you click than discover it afterwards.

## Your wallet is not your name. It is also not anonymous.

This site does not know who you are. It never asks. It holds nothing that would
answer the question. It cannot be forced to reveal what it does not have.

Aside from the faucet, the site does not keep your wallet, either. The page you
read never tells us which wallet is yours. We are told by your wallet when it
retrieves the transaction that setsup or renews your meter. We erase it when
you press continue.  What we keep is your meter and your fund.

That is not the same as being anonymous, and you should not let anyone tell you
it is. A wallet is known by its public key, and the key is a pseudonym — a name
you did not choose, which is nevertheless a name, and which the same person
tends to keep. Wallets get linked to people all the time: at the exchange where
you bought the tokens, which knows your identity because the law requires it;
by using one wallet across services that each know a little; by analysis firms
who do this professionally; sometimes just by timing. None of that involves us.
It is also something we cannot prevent. Any promise we cannot keep is worth
less than this plain description you are reading.

**One part of it is our doing, and you should know about it before you open a
meter.** Your meter is an account on a public blockchain. It names your fund,
and your fund names your wallet. Anyone who knows your wallet can find your
funds, and from them every site's meter that draws on them — your limit, how
much you have used, how much you have paid — and they can read it years from
now, because nothing on a public ledger is ever taken down. We did not choose
that property as a feature and we cannot switch it off. It is what putting a
spend meter on a public ledger means. It is a record that exists because you
used this site. Pointing at the technology would be a way of not telling you.

So: this site holds almost nothing about you. In exchange it writes one
permanent public line saying that your fund paid this site. Your fund says
whose it is. That is the actual trade. It is a good one for a demonstration
with a worthless token. It is a real thing to weigh on a site where you would
be spending real money and reading something you would rather not have on the
record. A second fund keeps two sites from seeing that they share a reader. It
does not hide either fund from someone who has associated your wallet with
personally identifying information.

**Your wallet also talks to us directly.** To set or renew a meter, your
wallet fetches the transaction from this site. So this site sees the IP
address of the device your wallet runs on, as any web server sees its
visitors. That lands in the request logs and is not recorded against your
wallet. Where your wallet then sends the transaction is between you and your
wallet's maker. We do not see it.

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
