---
title: Two orderings that disagree on purpose
slug: two-orderings
created: 2026-09-07
revised: 2026-09-17
metered: true
status: published
lede: >
  When this site charges for a page, the site records the grant without waiting
  for the chain to confirm. When a reader closes the meter, the site waits for
  the chain and then checks the account before deleting anything. The two
  orders are opposite on purpose. Making them match would put a bug in one of
  them.
reading_time: 9
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
it and sends it to an endpoint, the service that passes transactions to the
chain. The endpoint checks the charge first, against the accounts as they
stand. If the reader cannot cover the charge, the endpoint refuses it. Nothing
is sent, the server records nothing, and the reader gets a screen that says
why.

Otherwise the endpoint accepts the charge, and the server does not wait for
the chain. It records a grant for this reader and this article, marked
pending, and renders the article at once. Where the reader's meter would be,
the page says the charge is on its way.

The page then asks, in a second request, what became of the charge. That
request asks the chain up to six times, two seconds apart, whether the charge
has landed. Then one of four things happens:

- **Confirmed.** The server marks the grant confirmed, and the page shows the
  meter with the charge in it.
- **No answer yet.** The grant stays pending, and the page says the
  confirmation has not arrived. A later request for the article asks again.
- **Landed and failed.** The server marks the grant refused and keeps it. The
  page says the charge was turned down and nothing was charged.
- **Never landed.** A charge the chain still has not seen after two minutes
  can no longer land, because its blockhash has expired. The server marks the
  grant that way, and keeps it.

![A sequence diagram with four lifelines: the reader's browser, the site server, the site database and the chain. The browser posts the article. The server locks the wallet, finds no grant, and sends the charge it signed. Two branches. If the endpoint's check refuses the charge, nothing is sent, and the server returns why the charge failed, with nothing recorded. If the charge is accepted, the server records the grant as pending and returns the article with a line saying the charge is on its way. After a divider labelled the page asks what became of the charge: the browser posts a confirm, and the server asks about the signature up to six times, two seconds apart. Three branches. Confirmed: mark the grant confirmed, and return the meter with the charge in it. Landed and failed: mark the grant refused but keep it, and say the charge was turned down and nothing was charged. No answer yet: say it is not confirmed yet, and that a later request asks again. A note says the grant is written before the page is built, and is kept whatever the chain answers.](/assets/img/two-orderings-charge-light.png)
![A sequence diagram with four lifelines: the reader's browser, the site server, the site database and the chain. The browser posts the article. The server locks the wallet, finds no grant, and sends the charge it signed. Two branches. If the endpoint's check refuses the charge, nothing is sent, and the server returns why the charge failed, with nothing recorded. If the charge is accepted, the server records the grant as pending and returns the article with a line saying the charge is on its way. After a divider labelled the page asks what became of the charge: the browser posts a confirm, and the server asks about the signature up to six times, two seconds apart. Three branches. Confirmed: mark the grant confirmed, and return the meter with the charge in it. Landed and failed: mark the grant refused but keep it, and say the charge was turned down and nothing was charged. No answer yet: say it is not confirmed yet, and that a later request asks again. A note says the grant is written before the page is built, and is kept whatever the chain answers.](/assets/img/two-orderings-charge-dark.png)

Until the chain answers, the page shows none of the meter's numbers. A read
of the reader's accounts taken straight after sending would return the
numbers from before the charge. Those numbers would come from the chain, and
they would still be wrong.

The cases without a confirmation are the ones that matter. Two mistakes are
possible there, and they are not the same size.

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

A charge that lands and fails costs the same, plus the fee for the failed
transaction. The endpoint's check has already turned away the charges that
were bound to fail. What gets past it and fails anyway is a charge whose
accounts changed in the second between the check and the chain: the reader
spending from the same wallet in that moment, say. Taking the article back
afterwards would leave the reader wondering what happened to the page they
were reading.

This is what the reader sees when it happens. We forced this one on devnet
with a test setting, because a real one needs a reader racing the chain. The
meter's figures were read after the failure. Nothing in them moved: a charge
that fails changes nothing.

![The strip under an article after its charge was turned down. It reads: The network turned this charge down after the article was already on its way to you, so nothing was charged. The article is yours anyway — the site takes that loss rather than taking it back. Below that: used 0.09, settled 0, carried 0.09, 41 views left, a link to the meter, and a link to this transaction on chain.](/assets/img/two-orderings-absorbed-light.png)
![The strip under an article after its charge was turned down. It reads: The network turned this charge down after the article was already on its way to you, so nothing was charged. The article is yours anyway — the site takes that loss rather than taking it back. Below that: used 0.09, settled 0, carried 0.09, 41 views left, a link to the meter, and a link to this transaction on chain.](/assets/img/two-orderings-absorbed-dark.png)

So the site takes the cheaper mistake, on purpose, and says so on the page.

Not waiting also means the reader does not wait. Waiting for the chain before
answering held the reader on the old page for the whole window. That was
usually a second and at worst twenty. Now the wait happens in a request the
reader is not watching.

The grant is also written **before** the server builds the page. Suppose the
charge succeeds and building the page then fails. The reader sees an error
instead of the article, but has paid. Because the grant is already written,
the reader's next request for that article finds the grant and gets the
article without a second charge.
[The request nobody made](/a/request-nobody-made) says more about that second
request.

## Erase nothing until the contract account is gone

Closing works the other way round.

The reader's wallet signs and sends one transaction with two instructions. The
first closes the reader's contract with the site and forgives whatever is
unpaid. The second revokes the site's permission to draw from the reader's
token account. The browser then reports the transaction's signature to the
server.

The server asks the chain about that signature up to six times, two seconds
apart.

- **The transaction landed and failed.** The server deletes nothing, and says
  so.

Otherwise the server reads the reader's contract account from the chain, and
decides:

- **The account is gone.** The server deletes every session for that wallet,
  every grant, and the row it keeps for that wallet. The cookie is cleared.
  The page shows a receipt that counts what was deleted.
- **The account is still there.** The server deletes nothing. It writes a
  note that a close is pending, and tells the reader to reload in a moment.
  The section *Give the waiting path a way to finish later* explains the
  note.

![A sequence diagram with the same four lifelines. The reader's wallet sends close and revoke to the chain. The browser posts the close's signature to the server. The server asks about that signature up to six times, two seconds apart. If the transaction landed and failed, nothing is deleted. Otherwise the server reads the contract account. If the account is gone, the server deletes sessions, grants and the lock row, and returns a receipt of what was deleted. If the account is still there, the server writes a note that a close is pending and tells the reader to reload in a moment. A note says the signature only says when to look, and the missing account is the proof.](/assets/img/two-orderings-close-light.png)
![A sequence diagram with the same four lifelines. The reader's wallet sends close and revoke to the chain. The browser posts the close's signature to the server. The server asks about that signature up to six times, two seconds apart. If the transaction landed and failed, nothing is deleted. Otherwise the server reads the contract account. If the account is gone, the server deletes sessions, grants and the lock row, and returns a receipt of what was deleted. If the account is still there, the server writes a note that a close is pending and tells the reader to reload in a moment. A note says the signature only says when to look, and the missing account is the proof.](/assets/img/two-orderings-close-dark.png)

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
signature the server asks about is the server's own. The later request that asks
about it takes the signature from the site's own record of the grant, never
from the browser.

When a reader closes the meter, the reader's wallet sends the transaction. The
server only hears about the transaction from the browser. The signature is a
string from a place the server does not control. Even a genuine signature
shows only that something was submitted.

The server does ask for that signature's status. The answer tells the server
when to look. The answer is not the proof. The proof is the contract account. The
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

Waiting has a cost. Until this piece was written, the site had not paid all
of it.

Suppose the close lands at second twenty. The server asked for the last time
at about second twelve, read the chain, found the contract still there, and deleted
nothing. The reader reloads, as told. The meter now says that this wallet has
no contract and nothing to close. The meter is right. But the erasure ran only
in the request that saw the contract account gone, and that request had
already given up. The site kept the session and the grants that the close was
meant to delete.

A path that leans back needs a second way to reach its end. Charging has one
already: the pending mark on the grant is its note, and a later request
finishes the job. So the request that gives up on a close now leaves a note
too: a close was sent for this wallet, with this
signature. Every later request that knows its wallet checks for a note before
doing anything else. When a note is there, the server reads the contract
account again. If the account is gone, the server finishes the erasure, note
included, and the request goes on as if nobody were identified.

![A sequence diagram with the same four lifelines. The browser posts the close's signature. The server asks about the signature up to six times, reads the contract account, finds it still there, writes a note that a close is pending, and tells the reader to reload. The close then lands at second twenty. After a divider labelled the reader reloads: the browser asks for the meter, the server looks up the session and finds this wallet with a pending close, reads the contract account, finds it gone, deletes sessions, grants, the lock row and the note, and answers that no paying wallet is stored. A note says that without the note, the reload cannot tell a meter just closed from one never opened.](/assets/img/two-orderings-late-close-light.png)
![A sequence diagram with the same four lifelines. The browser posts the close's signature. The server asks about the signature up to six times, reads the contract account, finds it still there, writes a note that a close is pending, and tells the reader to reload. The close then lands at second twenty. After a divider labelled the reader reloads: the browser asks for the meter, the server looks up the session and finds this wallet with a pending close, reads the contract account, finds it gone, deletes sessions, grants, the lock row and the note, and answers that no paying wallet is stored. A note says that without the note, the reload cannot tell a meter just closed from one never opened.](/assets/img/two-orderings-late-close-dark.png)

Without the note, the reload cannot tell a wallet that has just closed its
meter from a wallet that has never opened one. Both show the same thing on
chain: no contract account.

The note has limits of its own, and they follow the same rule as the rest of
this piece:

- **If the chain does not answer,** the note stays and nothing is deleted.
- **If the contract is still there three minutes after the close was sent,**
  the close can no longer land, because its blockhash has expired. The note
  is dropped, and nothing is deleted.
- **If the reader never comes back,** the note waits, but not forever. The
  grants go within thirty-five minutes and the session within twelve hours,
  as they would for any reader. Once nothing is left for the note to erase,
  the scheduled sweep deletes the note too.

The note holds the wallet address and the close's signature. The chain
already shows both, in the close transaction itself. The privacy page lists
the note anyway, because it is something the site holds.

## Why the tidy version is worse

Make both paths wait and then write, and charging breaks. On a slow minute the
site refuses articles for charges that then land. The readers who paid for
nothing are exactly the readers who cannot tell.

![A sequence diagram of the wrong order for charging. The browser posts the article. The server sends the charge and asks about it six times while the chain is slow, then answers with no article, marked as the mistake. Afterwards the charge lands on the chain, also marked. A note says the reader has paid and has nothing to show for it, because the refusal was already on the screen when the charge landed.](/assets/img/two-orderings-tidy-charge-light.png)
![A sequence diagram of the wrong order for charging. The browser posts the article. The server sends the charge and asks about it six times while the chain is slow, then answers with no article, marked as the mistake. Afterwards the charge lands on the chain, also marked. A note says the reader has paid and has nothing to show for it, because the refusal was already on the screen when the charge landed.](/assets/img/two-orderings-tidy-charge-dark.png)

Make both paths write and then wait, and closing breaks. That bug would be
very hard to notice. When the close lands in time, the visible result is
identical. A close nearly always lands in time. The bug would show only when
the chain is slow, only for a reader who had just asked to end the
arrangement, and only as a receipt that says something false.

![A sequence diagram of the wrong order for closing. The wallet sends close and revoke. The browser posts the signature. The server deletes sessions and grants first, marked as the mistake, then asks about the signature six times while the chain is slow, and returns a receipt saying the contract is closed, also marked. A note says that if the close never lands, the contract stays open and the site can still draw from the reader's token account, while the receipt says the opposite and the reader has no reason to check it.](/assets/img/two-orderings-tidy-close-light.png)
![A sequence diagram of the wrong order for closing. The wallet sends close and revoke. The browser posts the signature. The server deletes sessions and grants first, marked as the mistake, then asks about the signature six times while the chain is slow, and returns a receipt saying the contract is closed, also marked. A note says that if the close never lands, the contract stays open and the site can still draw from the reader's token account, while the receipt says the opposite and the reader has no reason to check it.](/assets/img/two-orderings-tidy-close-dark.png)

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
