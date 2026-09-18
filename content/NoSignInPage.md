---
title: The ID of what's paying
slug: no-sign-in-page
created: 2026-09-07
metered: true
status: draft
lede: >
  Every site that monetizes itself reaches for the same shape first: prove who
  you are, then we'll leverage your use. This site was built from a diagram
  that never asked for that. The shape showed up anyway, because that is the
  shape of what software does now. What the meter actually needs to know is
  never a person.
reading_time: 6
---

*By Douglas Lovell with Claude Opus 5 (Anthropic)*

The original spec for this demonstration and its original construction had a
masthead with an implement identified as "sign-in". After running through a
sign-in screen, with a wallet identified, the masthead displayed a "sign-out"
implement.

## The objection

In the sol-pay library's state machine, `identified` is a choice, not a screen.
A wallet address is known or it is not. If not, the next stop is setting a
limit using a wallet. A sign-in screen was this site's own addition. Its
specification even said so in passing — a demo "needs a front door and a wallet
needs a sign-in" — and nobody had asked whether the second half of that was
true.

The objection was one sentence: *a sign-in page feels like identifying for
tracking.*

Which it is. That is what the sign-in page did. It collected an identity and
did nothing else.  That this site's version of "sign-in" held one address, a
session id and nothing more was not evident.  A reader who has spent twenty
years being asked to sign in before anything happens has very different
expectations about what that arrangement means.  The experience was,
you have identified.  The solution: no sign-in page.

The diagram had been saying so all along.

## Who is granting what to whom

The sign-in shape gets one thing backwards, and it is the thing that matters. A
sign-in admits a reader to a site, on the site's terms. What happens at this
meter is the reverse. The reader is about to grant the site something: a
scope, in tokens, that the reader chooses and that the site cannot exceed. The
site is the party being held to a limit. The reader can raise it, let it run
out, or revoke it. The site has no say in any of that.

Seen that way, "who are you" is the wrong question for the site to be asking.
The only thing it needs to know is which grant it is drawing on. The
address that signed the grant is the whole answer. The library underneath this
site drew that line on its first day: *who is this visitor* is a site's own
affair, and the payment core wants exactly one input, a wallet address: Not a
person. Not a session with a name attached. An address that can sign, and that
already has a balance.

The revised experience moved identification of the wallet into the meter
panel, one click before the grant, on the same screen as the price. A reader
who declines has lost nothing and is still reading the lede. A reader who
continues sees, in the same breath, what the address is for and what it will be
allowed to spend.

## A token, not an account

A wallet address is a pseudonym — a name nobody chose, that the same person
tends to keep, and that can be checked without being explained. It is not
anonymous: exchanges, address reuse, chain analysis and plain timing link
addresses to people every day, and none of that is this site's doing or this
site's to promise against. It is not an account either. An account has a
profile, a history, preferences — things a site keeps about someone across
visits, on purpose, because that is the product. A verifiable pseudonymous
token has none of that built in. It proves one thing: that whoever is here
right now controls the secret behind the wallet.

That is also why the wallet still signs something, even with the sign-in screen
gone.  It would be tempting to skip the signature too. The reader is about to
sign a transaction on chain. Watch for it and take its signer as the reader.
This is not sufficient because a landed signature is public. Anyone reading the
ledger could copy it into this site's form and be handed the session that draws
on somebody else's grant. The signature is there for the reader's sake as much
as the site's: it makes sure that nobody but the key holder can spend what the
key holder authorized. It proves possession and control of a financial
instrument, not identity.  The difference is at the core of this design.

Everything else follows from the address. The contract is derived from the site
and the payer rather than looked up, so a reader who comes back tomorrow with a
fresh session lands on the same grant with nothing remembered in between.

## The whole of making the relation

The whole of making the relation is not one, but two wallet interactions on a
first visit. The first proves control of the address. The second grants the
scope. No wallet does both in a single prompt. The two interactions sit behind
two clicks rather than one because a wallet interaction has to originate from a
real user gesture.  After the first wait for a wallet response initiated by a
click gesture, any second wallet response is outside of that gesture. On
Android that is a blocked navigation, not a warning. One wallet interaction per
click is also the more honest experience: proving control and granting scope
are not the same act.

## Postscript: the same mistake, one screen up

Our development work eliminated the sign-in screen in one revision. The
masthead prompt should have gone in the same one. It did not.

Across the top of every page, under the wordmark, the site had been saying
`signed in as 4xkQ…9fT`, with a link to sign out beside it. The objection that
killed the sign-in screen applies to a name and an exit repeated on every page
at least as well: that is what an account looks like, and an account is assumed
to have contents. Nobody had argued for the masthead either. It arrived as a
convention — sites that have sign-in have a signed-in indicator — and a
convention is never asked to justify itself.

It also had a bug, and the bug was the same fact seen from underneath. Closing
a contract erases the session. The meter reports that erasure in place,
rather than reloading, so a reader can watch the deletion happen. The masthead
had already rendered before any of that and went on saying *signed in as* to a
reader the site had just finished forgetting. One fact displayed in two
places. Only one of them reflected the change.

The address now appears exactly once, where it does work: on the meter, beside
what it is allowed to spend, next to a control that offers to forget it. The
vocabulary in use followed the change in focus-- *Signed in* and *signed out*
name a relationship this site does not have. Instead, the screens say the paying
wallet is **stored**, or **forgotten**.  That is a token kept or let go, not a
person let in or shown the door.

## The rule

Software defaults to identifying people, even when the transaction in front of
it does not need one. The default is not a technical requirement. It is the
shape twenty years of login screens have trained every builder to reach for
first — here, on a diagram that never asked for it, for a site whose whole
point is that the reader is the one setting terms.

What replaced it was not less than identification. It was narrower: a token,
proved by a signature, standing for *what* is paying rather than *who* is
paying.  [Privacy](/privacy) makes the fuller case for why that narrowness is
worth keeping on purpose. Here it is the smaller claim: asked what a page view
needs to know about its reader, the honest answer was never a person. It was an
address that can sign, and a limit that address chose.
