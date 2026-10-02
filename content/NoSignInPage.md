---
title: The ID of what's paying
slug: no-sign-in-page
created: 2026-09-07
revised: 2026-10-02
metered: true
status: published
lede: >
  Every site that monetizes itself reaches for the same shape first: prove who
  you are, then we'll leverage your use. A meter does not need that. What a
  page view needs to know is which meter it draws on, and whether the browser
  in front of the site is the one that meter names. Neither is a person.
reading_time: 6
---

*By Douglas Lovell with Claude Opus 5 (Anthropic)*

This site has no sign-in page. A reader opens an article, meets the meter
beside the lede, and either sets one up or keeps reading the lede. Nothing
asks who the reader is.

## The objection to a sign-in page

In the state machine that
[sol-pay](https://github.com/wbreeze/sol-pay#what-is-here) documents,
`identified` is a choice, not a screen. A browser holds a meter or it does
not. If not, the next stop is setting one up. A sign-in screen would be an
addition, and the objection to adding one is a single sentence: *a sign-in
page feels like identifying for tracking.*

The objection holds even when the site behind the page keeps almost nothing. A
reader who has spent twenty years being asked to sign in before anything
happens has firm expectations about what that arrangement means. The page
says: *you have been identified.* This site keeps one row and a cookie. A
screen whose only job is to collect an identity has no way to show a reader
that restraint.

## Who is granting what to whom

The sign-in shape gets backwards the thing that matters most. A sign-in admits
a reader to a site, on the site's terms. What happens at this meter is the
reverse. The reader is about to grant the site something: a limit, in tokens,
and an expiry. The reader chooses both, and the site cannot exceed either. The
site is the party being held to a limit. The reader can raise the limit, let
the meter expire, or close it. The site has no say in any of that.

Seen that way, "who are you" is the wrong question for the site to ask. The
site needs to know which meter it is drawing on, and nothing about the person
who opened it. The sol-pay client library draws that line itself: *who is this
visitor* is a site's own affair, and the library answers one narrow question
for the site, whether a signature is valid for the key a meter names.

So the identifying happens inside the meter panel, on the same screen as the
price. A reader who declines has lost nothing and is still reading the lede. A
reader who continues sees, in the same place, what the limit is and what the
site will be allowed to take.

## A key, not an account

The reader's browser makes a key the first time it sets up a meter. The key
stays in that browser, and the page's own script cannot read the secret half
out. The setup transaction writes the public half into the meter. From then
on the meter answers the site's question itself: the browser that can sign for
that key is the browser the meter names.

The key is made for this site and for nothing else. A browser keeps each
site's storage apart, so no other site can read the key, use it, or learn that
it exists. A second site that meters the same reader gets a second key, made
on that site's own pages, and the two keys have nothing in common. The key
cannot follow a browser from one site to another, because the key was never
the browser's. It belongs to one meter on one site.

Within this site the key is a pseudonym. Nobody chose it, the same browser
keeps it, and it can be checked without being explained. The key is not an
account. An account has a profile, a history, preferences, all things a site
keeps about someone across visits, on purpose, because that is the product. A
key proves one thing, that the browser here right now holds the secret half.

The browser still has to prove that, and the reason is that everything else
about a meter is public. Every meter is on the chain for anyone to read. A
site that took a browser's word for which meter it holds would hand any
visitor a session that draws on somebody else's fund. So the site issues a
random number, the browser signs it with the key, and the site checks the
signature against the key the meter names. The site forgets the number at
first use, so a copied proof is worth nothing. The proof shows possession of a
key. It says nothing about identity, and that difference is the core of this
design.

A publisher that already has accounts would record the meter on the account
row. Nothing else here would change.

## One wallet gesture

The wallet appears once. It signs one transaction that opens the reader's
fund, moves coin into it, and opens this site's meter on it, naming the key
the browser made. After that, reading does not require the wallet. A charge is
signed by the site, against the meter, within the limit the reader set.

A reader who comes back tomorrow needs no wallet either. The browser still
holds its key and knows which meter is its own. The browser proves the key,
and the site binds a fresh session to the same meter, with nothing remembered
in between.

## What the site does learn about the wallet

The page never tells the site which wallet the reader holds. The site still
meets the wallet's public key in two places, and an implementer should know
both.

The wallet gives its public key when it asks the site for the setup
transaction, because the site has to compose the transaction for that wallet.
This site keeps the public key until the reader continues, ten minutes at
most, and then erases it.

The chain keeps it for good. A fund names the wallet that opened it, and a
meter names its fund, so anyone who reads the meter can read the wallet. Two
sites that meter on the same fund can see that they share a reader. That link
is a property of a public ledger and of one fund used twice, not of the
browser's key and not of this site. A reader who wants two sites kept apart
opens a second fund. This site reads the wallet from the fund only to show it
back to the reader, in the inspector, as one more value that came from an
account.

## Where the meter shows

A name and a way out, repeated at the top of every page, is the furniture of
an account. It invites a reader to assume the account has contents. What
exists here is a cookie, a row naming one meter, and a meter on a public
chain.

So the meter shows in one place, where it does work: on the meter page, beside
what it has spent and what it may still spend, next to the control that closes
it. Closing the meter is how a reader makes the site forget them. The browser
signs the close with its key, the site erases its row, and the browser deletes
the key.

The words follow from that. The screens do not say *signed in* or *signed
out*. Those words name a relationship this site does not have. The screens say
this browser holds a meter for this site, and *close this meter* ends it.

## The rule

Software defaults to identifying people, even when the transaction in front of
it does not need a person. The default is not a technical requirement. It is
the shape twenty years of login screens have trained every builder to reach
for first.

What replaces it is not less than identification. It is narrower: a key,
proved by a signature, standing for *what* is paying rather than *who* is
paying. [Privacy](/privacy) makes the fuller case for why that narrowness is
worth keeping on purpose. Here the claim is smaller. Asked what a page view
needs to know about its reader, the honest answer was never a person. It was a
key that a meter names, and a limit the reader chose.
