---
title: The first transaction, and the one that counted
slug: first-transaction
created: 2026-09-05
revised: 2026-09-22
metered: true
status: published
lede: >
  On 5 September a validator accepted the first transaction this site's
  server ever built, and sol-pay touched the chain for the first time. The
  library's specification had already written down what would count as proof,
  and a transfer was not it. The proof came two days later, when a reader's
  metering call settled.
reading_time: 5
---

*By Douglas Lovell with Claude Opus 5 (Anthropic)*

On 5 September a validator on devnet accepted a transaction assembled by this
site's server. The fee was the standard 5,000 lamports for one signature.
Confirmation took two seconds. It was the first time anything built with sol-pay reached
the chain.

This piece is partly a keepsake of that run. It is also a record of what the
run did not prove, and of the condition that decided when the proof was good
enough.

## The gap

sol-pay's PHP client builds instructions. It does not send them.

Between an instruction and the wire sits a legacy transaction message. The
message has compact-u16 length prefixes, account keys deduplicated and sorted
into four partitions, three header counts, a program-id index for each
instruction, and a recent blockhash. A signature array goes in front of the
whole thing. At the time, nothing in sol-pay compiled a message, in any
language. Rust never needed to, because Solana publishes crates that do it.
Node never needed to either. In the browser, the wallet compiles the message
and the question never comes up.

sol-pay's specification said that "the integrator owns the connection". For
every consumer that existed when the sentence was written, owning the
connection cost nothing. For a PHP server, it meant writing a wire encoder by
hand. The sentence had not changed. The population it applied to had.

## Why not just write it

The tempting move is to write the encoder and eyeball the output. This project
had already learned what eyeballing costs.

Deriving a program address in PHP needs an on-curve check, and libsodium
appears to offer one. It does not.
`sodium_crypto_core_ed25519_is_valid_point` also demands prime-order subgroup
membership, a test *stricter* than Solana's. On the builds tested, the
function was not exposed at all. Where it was measured, it disagreed with
Solana on 44.5% of samples. That disagreement would have produced a different
bump seed, and a silently wrong address, on roughly half of all derivations.
There would be no error, only the wrong account, forever.

An unverified message encoder fails the same way. It builds a plausible
transaction that does the wrong thing, and then somebody signs it.

PHP libraries for transaction assembly do exist. The ones found were
abandoned, untagged, or unchecked against anything, and none came with
vectors to check them by. For an implementer in PHP, "a library exists" and
"a library can be trusted" are separate questions.

So the vectors came first. A small Rust program emits three compiled messages
and their wire bytes. `solana-message` and `solana-transaction` produce them;
nobody transcribes them by hand. The PHP encoder, `SolPay\Tx`, is then checked
against them byte for byte.

There are three cases rather than one, because a single case leaves most of
compilation unexercised:

- One instruction, signed and paid for by the same key, never populates the
  readonly-signer partition. An encoder that omitted that partition entirely
  would pass.
- Two instructions naming the same account, writable in one and readonly in
  the other, pin down how the flags merge.
- A third key paying for someone else's instruction catches the rule nobody
  guesses correctly. **The fee payer is pulled out and put first, not sorted
  into place.** The fee payer is also forced writable, even when the
  instruction marked it readonly.

Inside each partition, keys ascend by raw public-key bytes, not by the order
the instructions named them. An encoder that keeps instruction order there
builds a different message. The different message still looks entirely right.

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

The transaction is a plain transfer, and the choice was deliberate. The fee
payer signs and is written. The recipient is written and does not sign. The
System program is readonly and neither. Three of the four partitions, and the
fee-payer rule, are on the wire at once.

One small thing in that output is a habit rather than a detail. The amount is
650,240 lamports because the script asked the cluster what rent exemption
costs today. The number everyone remembers is 890,880. A figure hardcoded from
memory would have been wrong here, and nobody would have known why.

## What the run did not prove

sol-pay's specification had a sentence waiting on a condition. "It builds
instructions and decodes bytes" was to become "it builds instructions and the
message that carries them, and decodes bytes". The condition, written down
before the run, was that **the demonstrator had settled a metering call
against devnet**.

The transfer was not a metering call. The transfer carried no Anchor
discriminator, no cross-program invocation, and none of the library's own
account lists. The transfer showed that `SolPay\Tx` produces transactions a
validator accepts. It did not show that the library's own instructions ride
that encoder correctly. So the sentence stayed as it was. The specification
recorded something narrower and true instead. The objection it had rested on
was that no signature in the vectors was real, no blockhash was ever current,
and nothing had paid a fee. That objection was retired: one signature now
was real, one blockhash had been current, and something had paid.

## What did prove it

Three more results followed, and each one carried something the one before
could not.

- **Later on 5 September**, first-run setup sent `initialize_site` to the
  deployed metering program. That put one of the library's own instructions
  in front of the program that defines it: the discriminator, the account
  list and its flags, the borsh arguments, and a program address the program
  re-derives from its own seeds. A disagreement anywhere would have been
  refused.
- **On 7 September** the site metered a reader's page view. The
  `meter_and_settle` instruction was built by the PHP client, compiled by
  `SolPay\Tx`, signed by the site's authority, and accepted. Unlike setup,
  metering sits on the path every reader takes.
- **Later that day** a metering call settled, moving 0.15 DEMO from a reader's
  token account into the site's treasury. The settle carried what the other
  three could not: a cross-program invocation, a delegate, and a transfer.

The accepted call and the settled call are different claims. A
`meter_and_settle` whose unpaid total has not reached the collection threshold
raises `used` and moves nothing. Only the settle met the condition as written.
On 7 September the specification's sentence changed. sol-pay now "builds
instructions and the message that carries them, and decodes bytes". Every
other verb in that section survives unchanged. The library still does not
sign, send, or learn what happened to a transaction.

## The rule

The discipline is not in refusing the win. The discipline is in writing the
condition down first, in a place where it can be read back afterwards, in
terms specific enough that passing something adjacent cannot be mistaken for
passing it.

A test defined after the result is not a test. It is a description.
