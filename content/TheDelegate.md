---
title: The permission nobody shows you
slug: the-delegate
created: 2026-09-07
revised: 2026-09-22
metered: true
status: published
lede: >
  Setting a limit on this site grants a permission, and most wallets never
  display it. Closing takes the permission back. A site that meters this way
  should show the permission by pointing at the account where it lives, so the
  reader can look there without the site in between.
reading_time: 6
---

*By Douglas Lovell with Claude Opus 5 (Anthropic)*

When a reader sets a limit on this site, the reader's wallet asks for one
signature. That one signature does two things. A wallet shows neither of them.
Only one of them bears on the reader's money, and that one is the thing a
metering site owes its readers a way to see.

## Accounts, and who may write them

On Solana, all state lives in accounts. Every account has an owner, and the
owner is a program. Only the owning program may change an account's data. A
transaction does not write to an account directly. A transaction carries
instructions, each addressed to a program. The program decides what to
write and whose signature it requires.

A wallet is an account owned by the System program. The wallet holds SOL and
nothing else. Tokens are not in the wallet. Each holding of a token lives in a
separate **token account**, owned by the Token program. A token account's data
is a short record: the mint, an `owner`, an `amount`, and two fields that
matter here: `delegate` and `delegated_amount`. The `owner` field names the
reader's wallet. The reader's token account for this site's token sits at an
address derived from the wallet and the mint, so anyone can compute where it
is.

"Writing a field" on a token account therefore means one thing. An instruction
asks the Token program to change the record. The Token program checks that
the right party signed, and then makes the change. The metering program cannot
write the reader's token account at all.

![Five boxes, each labelled with the program that owns it. The reader's token account, owned by the Token program, has the fields owner, amount, delegate and delegated_amount. Its owner field points to the reader's wallet, owned by the System program. Its delegate field points to the contract account, owned by the metering program, with the fields limit, used and paid. Dotted arrows labelled seed run from the contract account to the reader's wallet and to the site account, because the contract's address is derived from both. The site account, also owned by the metering program, has the fields treasury and page price; its treasury field points to the site treasury, a token account with an amount. A dashed arrow from the reader's token account to the site treasury is labelled a charge: transfer_checked, signed as the delegate.](/assets/img/the-delegate-accounts-light.png)
![Five boxes, each labelled with the program that owns it. The reader's token account, owned by the Token program, has the fields owner, amount, delegate and delegated_amount. Its owner field points to the reader's wallet, owned by the System program. Its delegate field points to the contract account, owned by the metering program, with the fields limit, used and paid. Dotted arrows labelled seed run from the contract account to the reader's wallet and to the site account, because the contract's address is derived from both. The site account, also owned by the metering program, has the fields treasury and page price; its treasury field points to the site treasury, a token account with an amount. A dashed arrow from the reader's token account to the site treasury is labelled a charge: transfer_checked, signed as the delegate.](/assets/img/the-delegate-accounts-dark.png)

## What the reader grants

The reader's one signature covers a transaction with two instructions.

The first creates a **contract account**, owned by the metering program. The
contract account holds the limit the reader chose, a running total of what
the reader has used, and how much of that total has been paid. Its address is
derived from the site account and the reader's wallet, so each reader has
exactly one contract with each site. The contract account is the site's
bookkeeping. The bookkeeping is on chain so that it is not the site's word
against the reader's.

The second is `approve_checked`, addressed to the Token program. The Token
program sees the wallet's signature, matches it to the token account's `owner`
field, and writes two fields: `delegate` becomes the contract account's
address, and `delegated_amount` becomes the most the delegate may draw. The
contract records what the reader has spent. The delegate is the permission to
spend it.

## What the permission allows

The permission is narrow. It covers one token account, holding one token. It
stops at the approved amount.

A charge works like this. When enough unpaid use has built up, the metering
program asks the Token program to move tokens from the reader's token account
to the site treasury, with `transfer_checked`. The metering program signs that
request as the contract account. No private key exists for the contract
account's address; only the metering program can sign for the address, and
only under the program's own rules. The Token program checks that the signer
is the named `delegate`, and that the amount fits within `delegated_amount`.
The Token program then moves the tokens and lowers `delegated_amount` by the
same amount.

Nobody at the site holds a key that can move the reader's tokens outside the
metering program's rules. Narrow is still not nothing: until the reader
closes, the contract may draw up to the reader's limit.

Closing reverses both instructions, in one transaction and in a fixed order.
`close_contract` removes the contract account. `revoke`, addressed to the
Token program and signed by the wallet, clears `delegate` and
`delegated_amount`.

## Why the wallet is silent

A wallet is built to answer the question "what do I have?" A delegate is not
something the reader has. A delegate is something another party may take.
Until that party takes it, the reader's balance reads the same either way. The
number on the screen does not change when the reader grants the permission.
The number does not change when the reader revokes the permission either.

Some wallets let a user revoke approvals from a settings screen. A screen for
revoking is not the same as a display of what was granted. Neither one helps a
reader who has just closed a meter and wants to know whether the meter is
closed.

So the permission lives in a field that most wallets never put in front of
the reader. The wallet is not negligent. The wallet answers the question it
was built to answer. The site that asked for the permission is the one
place left to show it.

## Why the site's word is not enough

After a close, the site could simply tell the reader that the permission is
gone: the contract account is closed, and the reader's token account names no
delegate. The site already reads that token account, and could print "no
delegate" and move on.

That report would prove nothing. A site reporting that it no longer holds a
permission over the reader's account is exactly the assurance that a site has
no standing to give. The claim is about the reader's account. The reader
should be able to check the claim without trusting the party the claim is
about.

So this site does three things.

- The inspector shows the `delegate` and `delegated_amount` fields on every
  page. The inspector reads both fields back from the reader's token account;
  neither comes from the site's memory.
- The screen where the reader closes shows the delegate before the button. A
  permission the reader is about to withdraw is worth naming while the reader
  can still decide. The same screen gives the token account's address, with a
  link to open the account on an explorer. Before the close, the explorer
  names this site's contract as the delegate. Afterwards the explorer should
  name none.
- After the close, the receipt does not infer the result from a successful
  transaction. The receipt reads the token account again and reports the
  `delegate` field as it found it.

The explorer link is the only part of the three that amounts to proof. A site
that verifies its own claim has verified nothing.

**An assurance is worth what its reader can check, not what its author can
assert.** When a claim names a witness, name one the reader can call.

## Why the claim names the token account

The specification for this site lists the claims the demonstration has to
prove. The last of those claims is about leaving. The claim says that after
closing, the reader's token account shows no delegate — in the inspector, and
on any explorer.

The claim names the token account because the token account is where the
permission lives. The claim names an explorer because an explorer is a witness
the site does not control. The claim does not name the wallet, because a
wallet cannot testify: most wallets never display the field.

The close screen says the same thing in the reader's terms. The paragraph
beside the button reads: *Afterwards your token account will show no delegate
— in the table above, and on the explorer. Reading the token account is the
check worth doing.*
