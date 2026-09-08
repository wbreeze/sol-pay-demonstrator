---
title: Three wallets, one name, and a refusal that wasn't true
slug: three-phantoms
metered: true
status: draft
lede: >
  This site told a perfectly good wallet that it did not support the sign-in
  feature it was advertising. The wallet was fine. The site had thrown it away
  and kept a different one with the same name.
reading_time: 4
---

# Three wallets, one name, and a refusal that wasn't true

For a day this site would not let anyone in. Connect a wallet, and the panel
answered:

> A wallet is here, but it does not offer Sign In With Solana.

It said this in Brave. It said it in Firefox. The wallet in both was Phantom,
which has offered that feature since version 23.11.0, and which was sitting
right there advertising it.

## The first guess, which was wrong

Brave ships a wallet of its own, and it registers through the same mechanism an
extension does. That is a tidy explanation: two wallets in the browser, the
built-in one answering first, the site seeing something that genuinely lacks
the feature. It even suggests a tidy next step — test in Firefox, where there
is no built-in wallet to interfere.

Firefox said exactly the same thing, which should have ended the theory. It did
not immediately, because a good explanation is hard to put down, and the second
browser had its own plausible story available.

The mistake was reasoning about somebody else's software from the outside. That
is not a thing you can do carefully; it is a thing you can only do or not do.

## The instrument

So: a page that filters nothing. Every wallet that announces itself, with every
feature key it advertises, in the order they arrived and with the milliseconds
at which they did. The legacy injected objects too, and whether the browser
reports itself as Brave. And beside each wallet, a button that calls the
sign-in feature **whether or not the wallet claims to have it** — because "the
wallet lacks it", "the wallet has it under another name" and "the call works
anyway" are three different findings and the site's own check cannot tell them
apart. That is what made it useless as a diagnostic: it had already collapsed
the distinction.

The report came back in one look.

| chains | sign-in? |
| --- | --- |
| `solana:mainnet` `devnet` `testnet` `localnet` | **yes** |
| `bitcoin:mainnet` `testnet` `regtest` | no |
| `sui:mainnet` `testnet` | no |

Three wallets. All named `Phantom`. All registered at 0 ms. Brave's wallet was
never involved at all — the report says so in a field the site had never
bothered to read.

## The bug, which is one line

The site kept a registry of wallets and de-duplicated it by name, because
wallets do re-register — on reload, and after returning from an app switch —
and a list that grows a duplicate every time is its own problem.

So the Solana Phantom was overwritten by the Bitcoin one, and the Bitcoin one
by the Sui one. The survivor was Sui. Sui does not offer Sign In With Solana,
and the site, having discarded the wallet that did, reported that fact
accurately.

A name is a label for a *brand*. One extension publishes one wallet per network
and gives them all the same one, which is reasonable — it is the brand — and
which means the name identifies nothing. The registry is now keyed by object
identity, which is what "the same wallet registering twice" actually means, and
the site filters candidates by the chain it is on rather than by what they are
called.

## The second finding, free with the first

While the instrument was out, it recorded something else worth keeping.

A wallet's list of connected accounts is **empty on every page load** until
that page connects for itself. Signing in connects — but signing in ends in a
navigation, and the wallet the new page discovers starts empty. The site had
been reading that list on the next screen and concluding the reader had
switched accounts.

The fix is to reconnect before looking, silently first, which is what a wallet
does without a prompt for a site you have already authorized. The old error
message told the reader to "reconnect" without saying to what. It now names
both addresses: the one this site is waiting for, and the one the wallet is
actually on.

## The rule

Both bugs are the same shape. The site believed something about a wallet that
the wallet had never said: that its name identified it, and that its accounts
persisted across a page load. Neither belief came from anywhere. They were the
sort of thing that is true of most objects and simply isn't true of these.

**When your code can only be wrong inside somebody else's software, stop
reasoning and build the thing that reports back.** The instrument took an
afternoon and has since earned it twice more — once confirming that this site's
sign-in message is byte-identical to what the wallet actually produces, and
once proving a failure was not where everyone assumed.

And the smaller rule, which is the one that would have saved the day: a
diagnostic that shares an assumption with the code it is diagnosing is not a
diagnostic. The reason this one worked is that it was willing to call a feature
the wallet said it did not have.
