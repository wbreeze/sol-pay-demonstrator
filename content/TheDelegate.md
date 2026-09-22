---
title: The permission nobody shows you
slug: the-delegate
created: 2026-09-07
metered: true
status: draft
lede: >
  Setting a limit on this site grants a permission, and most wallets never
  display it. Closing takes the permission back. The honest way to show that
  is to point at the account where the permission lives, and to let the reader
  look there without the site in between.
reading_time: 5
---

*By Douglas Lovell with Claude Opus 5 (Anthropic)*

When you set a limit on this site, your wallet asked you to sign once. That
one signature did two things. A wallet shows you neither. Only one of them
bears on your money.

## What you granted

The first thing is a contract account. The contract account holds the limit
you chose, a running total of what you have read, and how much of that total
has settled. The contract account is the site's bookkeeping. It lives on chain
so that the bookkeeping is not the site's word against yours.

The second thing is a permission. The instruction `approve_checked` wrote two
fields onto your token account: a `delegate` and a `delegated_amount`. The
`delegate` names this site's contract. The `delegated_amount` is the most the
delegate may draw. The contract records what you have spent. The delegate is
the permission to spend it.

The permission is narrow. It covers one token account, holding one token. It
stops at the amount you approved, and the token program lowers
`delegated_amount` with every draw. The delegate is an address that only the
metering program can sign for. Nobody at this site holds a key that can move
your tokens outside the program's rules. Narrow is still not nothing: until
you close, the contract may draw up to your limit.

Closing takes back both, in one transaction and in a fixed order:
`close_contract`, then `revoke`.

## Why the wallet is silent

A wallet is built to answer the question "what do I have?" A delegate is not
something you have. A delegate is something another party may take. Until
that party takes it, your balance reads the same either way. The number on the
screen does not change when you grant the permission. The number does not
change when you revoke the permission either.

Some wallets let you revoke approvals from a settings screen. A screen for
revoking is not the same as a display of what you granted. Neither one helps a
reader who has just closed a meter on this site and wants to know whether the
meter is closed.

So the permission you gave lives in a field that most interfaces never put in
front of you. The interface is not negligent. The interface answers the
question it was built to answer.

## Why the site's word is not enough

The easy repair is for the site to say so. The site already reads your token
account, and could print "no delegate" and move on.

That report would be no repair. A site reporting that it no longer holds a
permission over your account is exactly the assurance that a site has no
standing to give. The claim is about you and your account. You should be able
to check the claim without trusting the party the claim is about.

So the site does three things.

- The inspector shows the `delegate` and `delegated_amount` fields on every
  page. The inspector reads both fields back from your token account; neither
  comes from the site's memory.
- The screen where you close shows the delegate before the button. A
  permission you are about to withdraw is worth naming while you can still
  decide. The same screen gives your token account's address, with a link to
  open the account on an explorer. Before you close, the explorer names this
  site's contract as the delegate. Afterwards the explorer should name none.
- After the close, the receipt does not infer the result from a successful
  transaction. The receipt reads your token account again and reports the
  `delegate` field as it found it.

The explorer link is the only part of the three that amounts to proof. A site
that verifies its own claim has verified nothing.

**An assurance is worth what its reader can check, not what its author can
assert.** When a claim names a witness, name one the reader can call.

## The sentence that survived

This site's specification lists the claims the demonstration has to prove.
The last of those claims is about leaving. The claim used to say that after
closing, "the wallet shows no delegate." The first time somebody closed a
meter and opened the wallet to check, the wallet showed a balance. There was
no delegate before and none after, so there was nothing to compare. The check
the claim asked for could not be performed.

The claim now names the token account as its witness, and an explorer as a
second one. The specification was corrected on the same day the close screen
was built.

The close screen was not corrected. Beside the button, a paragraph told
readers that their wallet would show no delegate afterwards, and called that
"the check worth doing." Forty lines higher, the same template explained that
most wallets never display a delegate at all. Both paragraphs went in the same
day. The wrong one stood for two weeks, in front of the reader it was written
for, at the moment that reader was about to act on it. Nobody noticed until a
review of this article checked the draft against the screen.

A corrected claim is corrected only where somebody went and corrected it. The
specification is where the builders read the claim. The screen is where the
reader does. The paragraph beside the button now says: *Afterwards your token
account will show no delegate — in the table above, and on the explorer.*
