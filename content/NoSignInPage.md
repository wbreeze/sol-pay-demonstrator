---
title: The screen the diagram never had
slug: no-sign-in-page
metered: true
status: draft
lede: >
  This site used to have a sign-in page. It was in the specification, it was
  built, and it worked. It was also absent from the state diagram the whole
  design had been drawn against — and the diagram was right.
reading_time: 4
---

# The screen the diagram never had

The objection was one sentence: *a sign-in page feels like identifying for
tracking.*

Which it is. That is what the page did. It collected an identity and it did
nothing else, and a reader who has spent twenty years being asked to sign in
before anything happens has learned what that arrangement usually means. The
fact that this site's version collects one address and a session id and holds
nothing else is a claim the reader has no reason to believe at the moment they
are being asked.

## What the drawing said

The library this site demonstrates ships a state diagram. It has cyan nodes for
screens and yellow for wallet interactions, and it had been the reference for
every design decision here for weeks.

It has no sign-in node.

What it has is `identified`, drawn as a choice — a diamond, not a screen — with
two edges: *viewer's wallet address known* goes on to find the contract, and
*viewer not identified* goes straight to `set_meter`. Identifying is a
condition to be evaluated, not a place to send somebody.

The specification for this site had added the screen anyway, and had even said
so out loud: five screens, three of them cyan nodes from the diagram, "the
other two exist because a demo needs a front door and a wallet needs a
sign-in." That sentence is where the invention is admitted, and nobody had read
it as an admission.

Nobody had read the diagram either. Two people had been designing against a
picture neither had looked at recently, and the picture had been right the
whole time.

## Where it went

Into the meter. On any article that is metered, the panel that would have said
"you need to pay for this" now also says "and here is who you would be paying
as", one click before the money and on the same screen as the price.

That placement is the argument. Identification here is real, and it is narrow —
one address, one session id — and the way to make the narrowness legible is to
put it next to the thing it is for. A reader who declines has lost nothing and
is still reading the lede. A reader who continues has been shown, in the same
breath, exactly what the identity buys and exactly what it costs.

## What could not be dropped

There is a tempting simplification, and it is wrong.

With no sign-in page, why sign anything? The reader is about to authorize a
contract. That transaction is signed by their wallet and lands on a public
chain. The site could simply watch for it and take the payer named in the
account as the reader's identity — no message, no signature, one fewer wallet
dialog.

**Transaction signatures are public.** Anyone watching the chain sees that
contract open, and the site would have no way to distinguish the reader from
someone who read the ledger and typed the same signature into the same form.
Whoever asks second gets a session belonging to whoever paid first, and reads
against their limit.

Making it first-claim-wins narrows the window to about a second and does
nothing at all for renewals. A race is not a boundary. So the wallet still
signs a message the server issued, and the verification that was written for
the sign-in page survives unchanged — it simply happens somewhere else.

## What it costs

Two wallet dialogs on a first visit: one proving who you are, one authorizing
the spend. No wallet does both in a single prompt.

They sit behind two clicks rather than one, and that is not a stylistic choice.
Every wallet call has to originate from a real user gesture, and after the
first `await` the second call is no longer inside one — on Android that is not
a warning, it is a blocked navigation. One dialog per click is also the more
honest description of what is happening, since the two are not the same act.

## The rule

**A document and a drawing of the same system are two claims, and they can
disagree without anyone noticing.** Prose accumulates; a diagram is redrawn.
This specification had grown a screen the diagram never gained, and the growth
was invisible because it happened one sentence at a time, each of them
reasonable.

The narrower rule underneath it: when a design adds something its reference
does not have, the addition should have to justify itself out loud. This one
had — in a subordinate clause, in a section nobody was reading for that
purpose. Which is not quite the same as being justified.
