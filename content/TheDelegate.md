---
title: The permission nobody shows you
slug: the-delegate
metered: true
status: draft
lede: >
  Authorizing this site did two things, and a wallet displays one of them.
  The claim that closing "leaves nothing behind" named a witness that cannot
  testify — and repairing the claim turned out to be more interesting than
  repairing anything in the code.
reading_time: 4
---

# The permission nobody shows you

This project keeps a list of things the demo has to prove. Six of the seven are
about money moving. The sixth is about it stopping:

> Leaving costs nothing and leaves nothing behind — `close_and_revoke`, and the
> wallet shows no delegate afterwards.

That claim was written months before anything could run it. When it finally
ran, the transaction confirmed, the contract account disappeared, and the
wallet showed exactly what it had shown before: a balance. No delegate before,
no delegate after, nothing to compare. The check the claim asked for could not
be performed.

The code was fine. The claim was wrong.

## Two things happened when you authorized

The first is the one this site talks about: a contract account came into
existence, holding a limit, a running total, and how much of it has settled.
That account is the site's bookkeeping, and it is on chain so that the
bookkeeping is not the site's word against yours.

The second is the one that actually matters to your money. `approve_checked`
wrote two fields onto **your token account** — a `delegate`, naming this site's
contract, and a `delegated_amount`, being how much that delegate may draw. The
contract records what has been spent. The delegate is the permission to spend
it.

Closing removes both, in one transaction, in that order: `close_contract`, then
`revoke`.

## Why the wallet is silent

A wallet is built to answer "what do I have". A delegate is not something you
have. It is something somebody else may take, and until they take it your
balance is identical either way. Nothing about the number on the screen changes
when you grant it, and nothing changes when you revoke it.

Some wallets will let you revoke approvals from a settings screen somewhere.
That is a different thing from showing you one, and neither helps a reader
standing on this page wanting to know whether what they just closed is closed.

So the permission you gave away lives in the one field no interface puts in
front of you, and the interface is not being negligent — it is answering the
question it was built to answer.

## Repairing the claim

The temptation is to fix this by having the site say so. The site reads the
token account on every request; it could simply print "no delegate" and move
on.

That is not a repair. A site reporting that it no longer holds a permission
over your account is exactly the assurance that a site has no standing to give.
The whole reason claim six exists is that it is supposed to be checkable by the
person it is about.

So the claim now points at the account rather than the wallet, and the site
does three things instead of one. The inspector shows the `delegate` and
`delegated_amount` fields, read back from your token account, on every page.
The screen where you close states what the delegate is *before* the button,
because a permission you are about to withdraw is worth naming while you can
still decide. And after the close, the receipt does not infer anything from the
transaction having succeeded: it reads the account again and reports the field.

Then it gives you the account address and a link to an explorer, which is the
only part of this that constitutes proof. A site that verifies its own claim
has verified nothing.

## What the failure actually was

Not a bug. A claim that sounded true, written by the people building the thing,
in a document nobody was checking it against, describing a check nobody had
tried to perform. It survived weeks of review — several rounds of it — because
every reader of that line already knew what a delegate was and pictured
themselves looking at one.

The thing that caught it was somebody following the instruction literally,
opening the wallet, and finding nothing there.

## The rule

**An assurance is worth what its reader can check, not what its author can
assert.** A falsifiable claim whose test nobody can run is not falsifiable; it
is a sentence with the shape of a promise.

And there is a smaller rule underneath it, which is the one that would have
caught this earlier: when a claim names a witness, name one that can be
subpoenaed. "The wallet shows" was a guess about somebody else's interface,
written into a specification as though it were a property of the system. The
token account is a property of the system. The two are not the same and the
sentence read identically either way.
