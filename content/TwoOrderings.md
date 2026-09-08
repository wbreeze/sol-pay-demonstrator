---
title: Two orderings that disagree on purpose
slug: two-orderings
metered: true
status: draft
lede: >
  Metering writes to the database before it trusts the chain. Closing trusts
  the chain before it writes to the database. Same codebase, opposite orders,
  and making them agree would put a bug in one of them.
reading_time: 4
---

# Two orderings that disagree on purpose

Two paths in this site do the same two things — talk to a chain, and change a
local record — in opposite orders.

**Metering a page view:** send the transaction, wait briefly for confirmation,
record the grant, render the article. If the confirmation does not arrive
inside the window, record the grant and render the article anyway.

**Closing a contract:** send the transaction, wait for confirmation, *check the
account is actually gone*, and only then delete anything. If the account is
still there, delete nothing and say so.

Anyone tidying this codebase would notice and fix one of them. That is the
thing worth writing down before someone does.

## Why metering leans forward

The two mistakes available at the metering step are not the same size.

Refuse to serve after sending a transaction that may have landed, and you have
charged a reader for nothing. That is the failure that destroys trust in a
payment system, and it is the one a reader cannot check, argue with, or
recover from — they simply paid and got a page saying no.

Serve after a transaction that may not have landed, and you have given away one
article at the page price. Here that is 0.01 DEMO, which is worth nothing at
all; on a real site it is a cent.

So the site absorbs the cheaper error deliberately, and flags the request as
unconfirmed rather than pretending it went cleanly. The grant is recorded
*before* the article is rendered for the same reason: a render that fails after
a successful charge should still leave the reader holding what they paid for.

## Why closing leans back

Now the mistakes swap sizes.

Closing purges this site's record of the reader — the session, every live view
grant — and signs them out. Do that on the strength of a transaction that may
not have landed, and you have erased a reader whose contract is still open and
whose limit is still being drawn against. They are signed out of a site that is
still authorized to take their money, with nothing on their screen to explain
it.

The other mistake is asking them to click a button again.

So closing waits, and then does something the metering path never does: it
looks at the account. The contract is a program-derived address, so the server
can derive it and read it. If it is gone, the close landed. That is the
condition for deleting anything.

## A signature is a claim; an account is a fact

The browser reports the signature after the wallet sends it. It is a string
from an untrusted place, and even taken at face value it says only that
something was submitted.

The site could poll that signature's status and take a confirmation as proof.
It does poll it — but it is not the proof. The account is. A contract that no
longer exists is not a report about a transaction; it is the state the
transaction was for, read back from the thing that decides it.

That distinction is small in code — a few lines either way — and it is the
whole difference between a site that believes its users' browsers and one that
does not have to.

## The tidy version, and why it is worse

Making both paths "wait, verify, then write" would break metering: the
unconfirmed case is precisely the one §7.3 argues about, and resolving it in
the reader's favour is the point.

Making both "write, then verify" would break closing, in a way that would be
extremely hard to notice, because the visible outcome is identical whenever the
transaction succeeds — which is nearly always. The bug would appear only on a
timeout, only for a reader who had just asked to be forgotten, and only as a
strange complaint that could not be reproduced.

## The rule

**Order the steps by which error you would rather make, and expect the answer
to differ between two places that look alike.** "We do it this way everywhere"
is a description of a codebase, not a reason.

And when the two do differ, say so in a comment where the second one lives.
Not because anyone will remember the argument, but because the alternative is a
consistency fix at some future date by somebody with every reason to think they
are cleaning up.
