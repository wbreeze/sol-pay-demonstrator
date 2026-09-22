---
title: The request nobody made
slug: request-nobody-made
created: 2026-09-11
revised: 2026-09-22
metered: true
status: published
lede: >
  Up to this writing, this site charged for an article on a GET. A browser
  makes that kind of request whenever it likes. Moving the charge to a POST
  closed a hole. The same move also removed the longest silent wait on the
  site.
reading_time: 8
---

*By Douglas Lovell with Claude Opus 5 (Anthropic)*

Up to this writing, this site charged for an article on a `GET`.

That is the usual shape of a metered page. The browser asks for the article.
The server decides whether this reader has paid, and takes the money while it
answers. The request that delivers the page is the request that bills for it.

The trouble is everything else that sends the same request. This is for
anyone about to meter their own pages. Each heading below is a rule the site
now follows. The text inside says what went wrong without the rule.

## Charge on a POST, never on a GET

A browser fetches URLs on its own account all day. It prefetches the link it
expects the reader to click. It prerenders a whole page in the background so
that the click feels instant. It fetches again when the reader presses back or
restores a window. A chat app fetches a pasted link to draw a preview card. None of those fetches is a
reader opening an article. All of them are `GET` requests.

Our defence was a list of three headers. Chrome sends `Sec-Purpose: prefetch`,
older browsers send `Purpose`, and Firefox has historically sent `X-Moz`. A
request carrying any of the three was served without a charge. The theory was
that a reader who goes on to click will pay on the next request.

Trusting the client there was the right instinct. A client that lies about a
prefetch gets one article for free, and that loss has a ceiling. The list was
the weak part. The list covered only the requests that announce themselves.
No header says "a crawler", "a link preview", or "a browser that spells the
header differently this year". The site was not asking whether a person wanted
the article. The site was asking whether the machine had been polite enough to
say that no person did.

HTTP already has a word for the property the site needed. `GET` is a **safe**
method. Every cache, proxy and browser is built on the promise that a safe
request can be repeated, prefetched, retried after a timeout or replayed by a
debugger, and that no repetition matters to anyone. A charge matters. So the
repair was not a longer list of headers. The repair was to take the charge off
the method that the whole web treats as free to repeat.

Now a `GET` for a metered article does one of two things. A reader holding a
live grant from an earlier charge gets the article. Any other reader the site
could charge gets the lede and a form, and the form posts to the same address.
The charge happens only in the `POST`. No prefetcher, previewer, crawler or
cache sends a `POST` on its own.

## Decide the GET without asking the chain

Moving the charge also fixed a problem we had been looking at for a different
reason.

Metering one view takes six round trips to a validator, one after another.
Response times for a quick sample of ten charging pages on the devnet chain and
RPC endpoint measured from 3.0 to 9.6 seconds, with a median of about five.
With the charge following inside of a `GET`, the browser received nothing until
all six round trips returned. The reader kept waiting on the *previous* page,
with no sign that the click had registered. The slowest and least explained
moment on the site was the moment the site took the reader's money.

The `GET` now decides what to send from a cookie and one row in a local table.
It asks, is there a live grant for this wallet and this article? That decision
needs no chain read, so the page with the form arrives in milliseconds. The
reader gets the headline, the lede and a line saying what is under way. The
validator works while the reader reads. When the `POST` answers, the article
and the inspector panel replace the form together. The request that does the
charging renders both of them.

One constraint makes it safe to replace the content returned by the `GET`.
**The page with the form shows nothing that a charge can change.** The price,
the meter's numbers and the reader's balances come from chain accounts. All of
those values arrive with the answer to the `POST`, read by the request that
charged. The page with the form does not show the price. A page that shows only
what the content index knows is never seen as stale after the charge that
follows it.

Without JavaScript, the form is a button labelled *Read on*. Pressing the
button sends the same `POST`, and the answer is a whole page.

## Hold the POST until someone is looking

Chrome's prerendering does more than fetch a page. Chrome's prerendering
**runs** the page, scripts included. A prerendered copy of the page with the
form would send the `POST` and charge for an article nobody had opened.

So the script that sends the form checks `document.prerendering` first. While
that flag is true, the script waits for the `prerenderingchange` event, which
fires when the reader actually arrives. That check is the only speculative-load
defence the site still needs. The contrast with the old design is this:
Before, every silent browser fetch was a charge unless the fetch identified
itself. Now the only route to a charge runs through the page's own script, and
that script knows whether anyone is there.

The back/forward cache raises a smaller version of the same question. Back can
restore the page with the form exactly as the reader left it: mid-`POST`, with
a status line about a wait that has already ended. The script listens for
`pageshow` and, on a page restored from the cache, sends the form again. Sending
the form twice is safe for the reason in the next section.

## Make the second POST free

A reader can send the same `POST` twice in several ways. A double click sends
it twice. So does pressing *Read on* again after an answer was lost. So does
a second tab. The site answers all of them the same way.

The server records the grant **before** it renders the article. A render that
fails after a successful charge therefore still leaves the reader holding the
view they paid for. The next `POST` for that article finds the grant and
charges nothing.

Two `POST` requests arriving at the same moment need one more piece. The check
for a grant and the charge both happen inside a lock on the payer's row in the
site's database. The first request charges and records the grant. The others
wait for the lock and then find the grant.

The code base shows the lock doing its job with a test that starts four
separate processes for one wallet and one article. The processes run a copy of
the metering step, with a pause standing in for the round trip to the chain.
The test holds all four at a barrier, then runs them nearly simultaneously
against one shared database. Exactly one records a charge. The same test runs
the four again with the lock removed. That run must record more than one. A race test that cannot fail proves
nothing. This one checks that it can.

Without JavaScript, the site answers the `POST` with the whole page rather than
a redirect. The usual pattern, post then redirect then get, would lose
something here. The redirect would land on the `GET`, the `GET` would find the
grant, and the page would say *served from a grant you already hold*. The
report of the charge and the link to its transaction would be gone. So the
`POST` answers directly, marked `Cache-Control: no-store`. A reader who
refreshes is asked whether to resubmit, and a resubmitted form finds the grant.

## Let the cookie refuse charges from other sites

A `POST` also closes a door that a charging `GET` left open. A page on some
other site could embed a form that posts to one of our articles, a forgery. The
session cookie is `SameSite=Lax`. A browser holding a `Lax` cookie does not
send it with a `POST` that another site started. A forged `POST` therefore
arrives with no session.  A request with no session belongs to no wallet. A request with no
wallet pays nothing.

A `SameSite` cookie setting of `Strict` would refuse more, and the site cannot
use `Strict`. On a phone, connecting a wallet switches to the wallet's app and
then back to the browser.  A `Strict` cookie is not sent on that return. The
reader would come back from the wallet as a stranger, in the middle of the step
that tells the site who they are.

## What we cannot tell you

We do not know whether anyone was ever charged for an article they did not
open.

A site that kept a record of who read what, and when, could find out. This site
deliberately keeps no such record. An article grant has an expiry. Within
five minutes of the grant lapsing, a sweep deletes the grant. The fact that the
reader read that article is then gone. The privacy promise that makes this site
worth demonstrating is the same promise that stops the site from auditing its
own past mistake.

That gap is a good argument for making the repair structural rather than
watchful. A site that cannot see an error must be built so that the error
cannot happen. *Built so* is still a claim, though, and a reader is entitled to
ask what checks the claim.

## What the tests check

The promise breaks into four smaller promises, and the code base has a test
for each. Every one of those tests has also been run against a broken copy of
the code, to show that the test can fail.

**Only a `POST` charges.** A test reads the file that registers the site's
routes. The test fails if the metering step is attached to anything but a
`POST`. The same test is run against the site's old shape, a `GET` that
meters, and must refuse that shape.

**Only a page that someone is looking at sends the `POST`.** A test starts a
real Chrome, asks the browser to prerender an article, and then follows the
link. The server writes down every request. With the check in place, the
`POST` arrives only after the reader does. With the check removed, the same
page charges during the prerender, before anyone has arrived.

Writing that test turned up a trap. Chrome turns prerendering off whenever the
browser is driven through DevTools, the protocol that browser-testing tools
such as Playwright and Puppeteer use. A test written with those tools gets an
ordinary page load on the click, and the test passes with the check deleted.
So this test starts Chrome as a plain program and trusts only the server's
record of requests. Before counting anything, the test also confirms from that
record that the prerender really happened.

**A `POST` that another site starts carries no session.** A test reads the
cookie the site issues and requires `SameSite=Lax`. The test fails on
`Strict`, on `None`, and on a cookie with no `SameSite` attribute at all.

**A second `POST` for a view already paid for charges nothing.** The race
described above covers the lock. A second test reads the metering code and
fails if anything happens before the lock is taken, or if the check for a
grant happens outside the lock.

Two gaps remain, and we would rather name them than leave them for a reader to
find:
- The route test reads the source. The route test does not start the site
  and send it requests, so a new route that reached the metering step under
  another name would get past the test.
- The race runs against the site's
  database, not against the chain. The chain's half, one charge on chain when two
  readers arrive at once, has been observed by hand on devnet, twice. An
  observation is not a test.

For anyone metering their own pages, the browser test is the one worth
copying, trap included. Once the charge is on a `POST`, a prerender is the one
way left for a browser to reach the charge without a person. Only a real
prerender shows whether a page waits for one.
