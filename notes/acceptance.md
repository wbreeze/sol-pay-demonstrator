# The acceptance record

`SPEC.md` §13 says the demonstration is done when a person who has never seen
it can do all of it from a link, in one sitting. This note attaches the
evidence to that sentence.

**Status: passed.** Recorded 2026-10-16, on the author's word that the
sessions of the capture plan were run. All of them were run on 2026-10-14.

## The sessions

The capture plan ran the demonstration as five short sessions. The plan lives
outside the repository, beside the recordings.

| session | where | covers | what the run folder holds |
| --- | --- | --- | --- |
| 1 | localhost, a fresh clone, the development wallet | clone-and-run (`SPEC.md` §12.0), one setup, one charge, the call counts | desk recording, HAR, terminal transcript, the canary's output |
| 2 | the hosted site, desk browser, wallet A on the phone | first visit, `SPEC.md` §13.1 steps 1 to 6, at a limit of 0.55 | desk recording, HAR, three wallet screenshots |
| 3 | the hosted site, the same desk browser, wallet A | renewal, the depleted fund, the close: `SPEC.md` §13.1 steps 7 and 8, and §13.2 | desk recording, phone recording, HAR |
| 4 | the hosted site, the phone alone, wallet B | the whole gesture on one device | phone recording, two screenshots. No HAR, by design: nothing is connected to the phone |
| 5 | the second device, `SPEC.md` §13.3 | optional | nothing. The run folder is empty |

## Where the evidence is

The recordings and HARs are on the author's machine, outside the repository,
one folder per session. They are not tracked: the recordings run to several
hundred megabytes each. `var/` is the wrong home for them too, because `var/`
holds keys.

The outside witness is the chain. A transaction cited from these sessions can
be opened on the explorer without trusting any recording.

## The canary, after session 1

Session 1 ran `bin/devnet-canary` from the fresh clone. The accounts it checked
are the clone's own, made by the clone's first-run setup. They are not the
author's instance in [`deployment.md`](deployment.md), and not the hosted
instance.

```
endpoint     https://api.devnet.solana.com
commitment   confirmed

health       ok
validator    4.4.0-beta.0
blockhash    417JABdTjftJxSBMvpMsB7u4h39bdYubsQtDAUmDLCjr
height       498250775

sol-pay      F8UDAGgxVTm8Vmh4RmskpMBCFqhRvuTqbDxDCj8UMedL  deployed
spl-token    TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA  deployed

mint         E2FmrwtpZ8SWYkESYkD8DPotrgc7h5Q5rzran9zRFom1  present
treasury     AhYZqTJDnDS6ffzA889qttVdJAtjbBQ8r68V852uckVd  present
site         2VhVmzgxTVTus9PdKH3FGX3oXxW2gCh5sApiNq8zAbSG  present

faucet       GfFTEh6iG1sP5evEzdzprQu9V1WrvnigyAZF3ntn9fPN
balance      0.2248 SOL — about 19 more visitors

ok — devnet still holds what this repository assumes it holds.
```

## Not recorded here

- The observation sheets, as filled. The figures read off the screen are in
  the recordings and HARs, not transcribed.
- The signatures, with explorer links. The HARs and the inspector footage
  carry them.
- The hosted instance's site, mint and treasury. Those belong in
  [`deployment.md`](deployment.md), beside the author's instance, and are not
  there yet.
