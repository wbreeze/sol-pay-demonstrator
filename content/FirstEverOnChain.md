---
title: The transaction that proved less than it looked like
slug: first-transaction
metered: true
status: draft
lede: >
  A validator accepted the first transaction this project ever built in
  PHP. The sentence in the library's spec that was waiting on it is still
  unchanged — and declining to change it is the decision worth reading
  about.
reading_time: 4
---

# The transaction that proved less than it looked like

On 5 September a validator accepted a transaction assembled by this site's
server. It cost about a hundredth of a cent in devnet fees and it took two
seconds. The interesting part is what happened next, which is that the sentence
in sol-pay's specification that had been waiting for exactly this did not
change.

## The gap

sol-pay's PHP client will build you an instruction. It will not send one.

Between those two things sits a legacy transaction message: compact-u16 length
prefixes, account keys deduplicated and sorted into four partitions, three
header counts, the program-id index, a recent blockhash, and a signature array
in front of the whole thing. Nothing in sol-pay compiled one, in any language.
Rust never needed it — Solana publishes crates that do it. Node never needed
it. In the browser, the wallet compiles the message and the question never
comes up.

So "the integrator owns the connection" had been free for every consumer that
existed when it was written. The sentence never changed. The population it
applied to did.

## Why not just write it

The tempting move is to write the encoder and eyeball the output. This project
had already learned what that costs.

Deriving a program address in PHP needs an on-curve check, and libsodium
appears to offer one. It does not: `sodium_crypto_core_ed25519_is_valid_point`
also demands prime-order subgroup membership, which is *stricter* than what
Solana does. On the builds tested it isn't exposed at all — and had it been, it
disagreed with Solana on 44.5% of samples, which would have produced a
different bump and a silently wrong address on roughly half of all derivations.
No error. Just the wrong account, forever.

An unverified message serializer fails the same way. It builds a plausible
transaction that does the wrong thing, and then somebody signs it.

There was a second wrong turn worth admitting, because it was the more
embarrassing one. The first draft of the note proposing this work claimed PHP
had no library for transaction assembly. That was false — there are several.
They are abandoned, or untagged, or unchecked against anything, which is a
better argument than the one that was made and was a single search away.

So: vectors first. A small Rust binary emits three compiled messages and their
wire bytes, produced by `solana-message` and `solana-transaction` rather than
transcribed by hand. Then the PHP encoder, checked against them byte for byte.

Three cases, not one, because one case leaves most of compilation unexercised.
One instruction signed and paid for by the same key never populates the
readonly-signer partition, so an encoder that omitted that partition entirely
would pass. Two instructions naming the same account writable in one and
readonly in the other are what pin the flag merge. And a third key paying for
someone else's instruction is what catches the rule nobody guesses correctly:
**the fee payer is pulled out and put first, not sorted into place** — and is
forced writable even when the instruction marked it readonly. Inside each
partition, keys ascend by raw public-key bytes, not by the order the
instructions named them. An encoder that preserves instruction order there
builds a different message that still looks entirely right.

## The run

```
sol-pay-demonstrator[master]$ bin/devnet-smoke
endpoint   https://api.devnet.solana.com
authority  163aJWGmry7Q2gWjtxmTbdC7NGFc7FecSN1gfpNUgRt
balance    4.999344760 SOL
recipient  Ghnpu6U5kYraYDm6zD3oViwdUTaBsR6pjTLBGTAnr4KL
sending    650240 lamports (rent-exempt minimum for 0 bytes)
header     1/0/1 (signers / readonly signers / readonly others)
keys       163aJWGmry7Q2gWjtxmTbdC7NGFc7FecSN1gfpNUgRt
           Ghnpu6U5kYraYDm6zD3oViwdUTaBsR6pjTLBGTAnr4KL
           11111111111111111111111111111111
message    150 bytes

CONFIRMED  m3NHj4v9QNdJzxionD9xLddd8i5sdCGDJ6ypNvb7dEtHrxpi41kNKomQYDL7VwqWDVAg5jUBWMQP99qwLjg7SEm
explorer   https://explorer.solana.com/tx/m3NHj4v9QNdJzxionD9xLddd8i5sdCGDJ6ypNvb7dEtHrxpi41kNKomQYDL7VwqWDVAg5jUBWMQP99qwLjg7SEm?cluster=devnet
```

A plain transfer, chosen rather than convenient: the fee payer signs and is
written, the recipient is written and does not sign, and the System program is
readonly and neither. Three of the four partitions, and the fee-payer rule,
all on the wire at once.

One small thing in that output is a habit rather than a detail. The amount is
650,240 lamports because the script asked the cluster what rent exemption costs
today. The number everyone remembers is 890,880. Had it been hardcoded from
memory, it would have been wrong here and nobody would have known why.

## What it did not prove

sol-pay's specification carries a sentence under amendment. "It builds
instructions and decodes bytes" was to become "it builds instructions and the
message that carries them, and decodes bytes" — once, in the words written down
well before this run, **the demonstrator had settled a metering call against
devnet**.

This was not a metering call. It was a transfer. It carries no Anchor
discriminator, no cross-program invocation, and none of the library's own
account lists. What it demonstrates is that the encoder produces transactions a
validator accepts. That is not yet the claim that the library's own instruction
rides that encoder correctly.

So the amendment stayed held. What the specification records instead is
narrower and true: the objection it had rested on — no signature in those
vectors is real, no blockhash was ever current, nothing had paid a fee — is
retired, because one is, one was, and something has.

## The rule

The discipline is not in refusing the win. It is in having written the
condition down first, in a place where it could be read back afterwards, in
specific enough terms that passing something adjacent could not be mistaken for
passing it.

A test you define after you have the result is not a test. It is a description.
