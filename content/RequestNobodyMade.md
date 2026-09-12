---
title: The request nobody made
slug: request-nobody-made
metered: true
status: draft
lede: >
  For most of this site's life, reading an article charged you on a GET — the
  one kind of request a browser is free to make without being asked. The
  defence was a list of headers that only catches the browsers polite enough
  to announce themselves.
reading_time: 4
---

# The request nobody made

Until yesterday, this site charged you a cent on a `GET`.

That is not unusual. It is how a metered page works nearly everywhere: the
browser asks for the article, the server decides whether this reader has paid,
and somewhere in the middle of answering, it takes the money. The request that
delivers the page is the request that bills for it.

The trouble is what else makes that request.

## Requests a reader did not make

A browser fetches URLs on its own account all day. It prefetches the link it
thinks you are about to click. It prerenders a whole page in the background so
the click feels instant. It asks again when you press back, or restore a
window, or when a chat app expands your link into a little card with a title on
it. None of that is a reader opening an article. All of it is a `GET`.

Our defence was a list of three headers. Chrome sends `Sec-Purpose: prefetch`,
older browsers send `Purpose`, Firefox has historically sent `X-Moz`. If a
request carried any of them, the site served the article without metering it —
free, on the theory that a reader who goes on to click will pay on the request
that follows.

Trusting the client there is the right instinct. A lying client asks to be
given an article for free, which is a small problem and a bounded one. But the
list only ever covered the requests that announce themselves, and there is no
header for "a bot that wanted to see your page", or "a link preview", or "a
browser that spells it differently this year". The site was not asking whether
a human wanted the article. It was asking whether the machine had been polite
enough to say it did not.

## The word HTTP already has for this

`GET` is a **safe** method. That is not a suggestion about style; it is the
promise every intermediary in the world is built on. A safe request can be
repeated, prefetched, cached, retried after a timeout, replayed by a debugger.
Nothing that happens as a consequence of it is supposed to matter to anyone.

Charging a reader matters. So the fix was not a longer list of headers. It was
to stop putting the charge on a method that the whole web treats as free to
repeat.

Now a `GET` for a metered article does one of two things. If you hold a live
grant from a charge you already paid, it serves the article. If you do not, it
serves the lede and a form — and the form `POST`s to the same address. The
charge lives in the `POST`, and no prefetcher, previewer, crawler or cache in
the world issues one of those on its own.

## The part that was a gift

Splitting the request in two solved a problem we had been staring at for a
different reason.

Metering a view takes six round trips to a validator, which measured between
three and ten seconds. Until yesterday, all of that happened before the browser
received a single byte, so the reader sat looking at the *previous* page — no
spinner, no flicker, nothing to say the click had registered. The slowest,
least explicable moment on the site was the moment it was taking your money.

The shell arrives in milliseconds, because deciding to send it costs no chain
read at all: a cookie, and one row in a local table. So the reader gets the
headline, the lede, and a line saying what is going on, while the validator
does its work behind them. When the answer comes back, the article and the
inspector panel replace what was there, both rendered by the request that did
the charging.

One repair, two problems, and they were the same problem: the site was doing
something expensive and consequential inside a request that was supposed to be
neither.

## One hole left, and it is a different shape

Chrome's prerendering does not just fetch a page. It **runs** it — scripts and
all. A prerendered copy of the shell would happily post the form, and charge
for an article nobody had opened yet.

So the script that sends the form waits until the page is actually being
looked at. That is a one-line check against `document.prerendering`, and it is
the only speculative path that still needs one. The difference matters: before,
every silent browser fetch was a charge unless it identified itself; now, the
only way to reach the charge is to run the page's own code, and the page's own
code knows whether anyone is there.

Two other things fall out for free. A form on somebody else's site cannot post
a charge on your behalf, because the session cookie is `SameSite=Lax` and never
travels with a cross-site `POST` — such a request arrives anonymous and pays
nothing. And a `POST` sent twice, by a double click or a retry, finds the grant
the first one recorded and charges nothing the second time.

## What we cannot tell you

The honest ending is that we do not know whether anyone was ever charged for an
article they did not open.

We could find out on a site that kept a record of who read what and when. This
one deliberately does not: there is a grant with an expiry, and when it lapses,
the fact that you read anything at all is gone. The privacy promise that makes
this site worth demonstrating is the same promise that means it cannot audit
its own past mistake.

Which is a decent argument for the repair being structural rather than
watchful. A site that cannot see the error it is making has to be built so the
error is not available.
