---
title: The error log that keeps a reader's spending
slug: the-logs
created: 2026-09-23
metered: true
status: published
lede: >
  A failed charge returns a number, and the number does not say which program
  raised it. The answer is in the transaction logs. The same logs carry the
  reader's wallet address and spending, so a site that forwards them to an
  error tracker builds the profile it set out not to keep.
reading_time: 4
---

*By Douglas Lovell with Claude Opus 5 (Anthropic)*

When a metering call fails, the endpoint returns an error, and the error
carries a code. A site needs the code to tell the reader what went wrong. The
code alone is not enough, and what supplies the rest is exactly the material
a privacy-minded site should not keep.

## A code does not name its program

A charge involves two programs. The
[sol-pay](https://github.com/wbreeze/sol-pay#what-is-here) metering program
counts the view. When money moves, the metering program asks the Token program
to make the transfer. Either program can refuse, and each numbers its errors
independently:

- `LimitReached` is 6003, from the metering program. The reader has reached
  the limit. The response is to offer renewal or closing.
- `InsufficientFunds` is 1, from the Token program. The reader's balance, or
  the approved amount, no longer covers the transfer.
- `OwnerMismatch` is 4, from the Token program. The site's contract is no
  longer the delegate on the reader's token account.

A refusal inside the transfer is reported against the top-level instruction,
`meter_and_settle`. So the error's instruction index points at the metering
program even when the Token program raised the code. A site that assumes the
code belongs to the program it called will name the wrong program precisely
when the difference matters.

The raising program is in the transaction logs. A node returns them inside its
reply, in `error.data.logs`, not as a file anywhere. Each program that fails
leaves a line beginning `Program <address> failed`, and the last such line
names the one that raised the error. The sol-pay client library's `cause` takes
that address and the code and returns one of three things: an error from the
metering program, an error from the Token program, or an unknown code from a
named program.

## What else the logs carry

Transaction logs and the transaction's account list carry the reader's wallet
address. The metering program's events carry amounts: the page-view count,
`used`, `paid` and `transferred`, encoded as base64 lines that anyone can
decode. None of it is secret. All of it is on chain already, readable by
anyone holding the signature. A fair question follows: if analysis firms can
mine the ledger, what does a saved log add?

The ledger holds a pseudonym: a wallet address. That address stands in for a
reader without saying who the reader is. Associating the address with a person
is work. That work is what those firms sell. A log that the site retains or
shares has the work already done. The site's own session id sits in the same
record as the wallet address, beside the reader's IP address and the time. The
site associates a reader it recognizes and a pseudonymous address that the
ledger keeps forever.

The association, once made, goes wherever the error goes. Shared or retained by
an error tracker, an application log, an analytics pipeline, or any of the
like, it leaks. None of those is scoped to hold spending histories. Several are
run by third parties. The site's own application log is no exception, sitting
under a rotation schedule set years ago for reasons that had nothing to do with
readers.

Where the association comes to rest decides what can happen to it. The ledger
does not hold the association ready made. Further, the ledger has no operator.
Nothing about the ledger can be breached, subpoenaed, or quietly given a longer
retention period. The ledger is not by itself a leak. The leak is any retention
or sharing of the association. Whatever holds the association carries all of
the exposures that the ledger does not, and can change owners besides. By
retaining or sharing the association, a site that chose per-view payment in
order not to profile its readers generates a profile anyway, one failed charge
at a time, in a system nobody thinks of as holding reader data.

## One place reads the logs

One class reads the logs of a failed charge. The logs do not leave it. The
class takes the endpoint's raw error and returns three things: a short message,
the `Cause`, and the code. Everything else is dropped before the class returns.
Nothing downstream can log what it never received.

Two details make that rule hold:

- **Error messages can carry the logs inline.** Some endpoints put the whole
  log array into the error's message field. The class keeps the message's
  first line, cut to 200 characters, so the rule is not defeated by a string
  field.
- **No logs means no guess.** A charge that lands and then fails comes back
  from a status query with a code and no logs. Without the raising program,
  the class returns no `Cause` rather than attributing the code to the
  instruction's own program.

The inspector shows the decoded cause and a link to the transaction on an
explorer. The reader who owns the wallet can read the full logs there, where
the logs belong.

## One code that needs a second read

`InsufficientFunds` stays ambiguous even with the right program attached. The
Token program returns it for a short balance and for a short approved amount,
and the two need opposite responses: a short balance needs a top-up, and a
short approval needs renewal. The client library's `diagnose` settles it with a
read rather than a guess. The site reads the reader's token account and
compares the balance and the approved amount with what the transfer needed.
Both shortfalls can be true at once, and a site should show both, balance
first, because a renewal the balance cannot cover fixes nothing.

## The rule

**Extract the program and the code, then discard the rest.** Diagnosing a
failed charge needs two facts from the logs. Keeping the logs keeps everything
else in them, the wallet address included. A wallet address kept beside a
session id is the association. The error path is where a careful site is most
likely to copy reader data somewhere it never meant to, because nobody reviews
an error handler for what it remembers.
