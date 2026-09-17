---
title: Two orderings that disagree on purpose
slug: two-orderings
created: 2026-09-07
metered: true
status: draft
lede: >
  When this site charges for a page, the site records the grant without waiting
  for the chain to confirm. When a reader closes the meter, the site waits for
  the chain and then checks the account before deleting anything. The two
  orders are opposite on purpose. Making them match would put a bug in one of
  them.
reading_time: 6
---

*By Douglas Lovell with Claude Opus 5 (Anthropic)*

Two paths on this site do the same two things: send a transaction to the
chain, and change a record in the site's own database. The two paths do those
things in opposite orders.

The first path charges for a page view. The second path closes a reader's
meter, ending the arrangement and erasing what the site holds about the
reader. Anyone who builds both will notice the mismatch and want to make the
two paths consistent. Either consistent version puts a bug in one of the
paths, and the bug shows only on the rare request where the chain is slow.

This piece is for anyone about to build both. Each heading below is a rule the
site follows. The text under each heading says what goes wrong without the
rule.

## Record the grant without waiting for the chain

To charge for a page view, the server builds the metering transaction, signs
it and sends it. The server waits up to twenty seconds for the chain to
confirm. Then one of three things happens:

- **Confirmed.** The server records a grant for this reader and this article,
  and renders the article.
- **No answer inside the window.** The server records the grant and renders
  the article anyway. The page tells the reader that the confirmation did not
  arrive.
- **Landed and failed.** The server records nothing. The reader gets a screen
  that says why the charge failed.

The middle case is the one that matters. Two mistakes are possible there, and
they are not the same size.

Refuse the article after sending a charge that may still land, and a reader
has paid for nothing. The reader has no way to know. The transaction lands
seconds later, after the refusal is already on the screen. A payment system
that does this once in a while loses the trust it runs on, and nobody can
point to the moment it happened.

Serve the article after a charge that never lands, and the site has given away
one page view. Here the page price is 0.01 DEMO, a test token worth nothing.
On a real site the loss is one page price. The price we argue for in
[What a dime has to do](/a/pay-per-view) is ten cents. The loss has a
ceiling, and the site is the one that pays it.

So the site takes the cheaper mistake, on purpose, and says so on the page.

The grant is also recorded **before** the article is rendered. A render that
fails after a successful charge still leaves the reader holding the view they
paid for. [The request nobody made](/a/request-nobody-made) explains why the
next request for that article is then free.

## Erase nothing until the contract account is gone

Closing works the other way round.

The reader's wallet signs and sends one transaction with two instructions. The
first closes the reader's contract with the site and forgives whatever is
unpaid. The second revokes the site's permission to draw from the reader's
token account. The browser then reports the transaction's signature to the
server.

The server waits up to twenty seconds for the chain to confirm that signature.
Then the server reads the reader's contract account from the chain, and
decides:

- **The account is gone.** The server deletes every session for that wallet,
  every grant, and the row it keeps for that wallet. The cookie is cleared.
  The page shows a receipt that counts what was deleted.
- **The account is still there.** The server deletes nothing. The reader is
  told to reload in a moment and close again.
- **The transaction landed and failed.** The server deletes nothing, and says
  so.

Now the two mistakes have swapped sizes.

Erase on the strength of a close that has not landed, and the receipt says
the contract is closed. The contract is still open. The site is still
permitted to draw from the reader's token account. The grants for articles the
reader has already paid for are gone. The reader leaves believing that an
authorization has ended when the authorization is still live. Ending the
authorization was the whole reason for the click. Nothing afterwards prompts
the reader to check the receipt against the chain.

The other mistake is to keep the records a little longer and ask the reader to
try again.

So closing waits, and closing takes the cheaper mistake too. The cheaper
mistake is simply on the other side this time.

## Take the account as proof, not the signature

The two paths differ in one more way, and the difference decides what counts
as proof.

When the site charges, the server signs and sends the transaction itself. The
signature the server polls is the server's own.

When a reader closes the meter, the reader's wallet sends the transaction. The
server only hears about the transaction from the browser. The signature is a
string from a place the server does not control. Even a genuine signature
shows only that something was submitted.

The server does poll that signature's status. The poll tells the server when
to look. The poll is not the proof. The proof is the contract account. The
account's address is derived from the site's address and the reader's wallet,
so the server can find the account without asking anyone. A contract account
that no longer exists is not a report about a transaction. A missing account
is the state the transaction was for, read back from the chain that holds it.

Opening a meter follows the same rule in the other direction. After the reader's
wallet sends the transaction that opens a contract, the server looks for the
contract account and trusts nothing else.

In code, the difference between trusting the signature and reading the
account is a few lines. Those few lines are the difference between a site
that believes its readers' browsers and a site that does not have to.

## Give the waiting path a way to finish later

Waiting has a cost, and up to this writing the site has not paid all of it.

Suppose the close lands at second twenty-five. The server stopped polling at
second twenty, read the chain, found the contract still there, and deleted
nothing. The reader reloads, as told. The meter now says that this wallet has
no contract and nothing to close. The meter is right. But the erasure only runs
in the request that sees the contract account gone, and that request has
already given up. The site keeps the session and the grants that the close
was meant to delete.

A path that leans back needs a second way to reach its end. The request that
times out has to leave a note: a close was sent for this wallet, with this
signature. A later request that finds the contract gone and the note in place
then finishes the erasure. Without that note, a later request cannot tell a
wallet that has just closed its meter from a wallet that has never opened one.

*[Draft note for Gato: this section describes the code as it stands. If the
note-and-finish repair goes in first, this section becomes the story of the
repair.]*

## Why the tidy version is worse

Make both paths wait and then write, and charging breaks. On a slow minute the
site refuses articles for charges that then land. The readers who paid for
nothing are exactly the readers who cannot tell.

Make both paths write and then wait, and closing breaks. That bug would be
very hard to notice. When the close lands in time, the visible result is
identical. A close nearly always lands in time. The bug would show only when
the chain is slow, only for a reader who had just asked to end the
arrangement, and only as a receipt that says something false.

## The rule

**Order the steps by the mistake you would rather make.** Ask who can see
each mistake, and what each mistake costs that person. Expect the answer to
differ between two places that look alike. *We do it this way everywhere*
describes a code base. The phrase is not a reason.

When two such paths do differ, say so in a comment at the second one. Nobody
will remember the argument. The comment is there for the day somebody finds
the mismatch and has every reason to believe that fixing the mismatch is
cleaning up. On this site, that comment sits on the route that finishes a
close. The comment says that the order is the reverse of the charging path's,
and why.
