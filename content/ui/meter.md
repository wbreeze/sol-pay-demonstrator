---
screen: meter
---

## anonymous.cost

Reading on costs {page_price} {symbol} an article, drawn from a limit you set
yourself and can close at any time.

## anonymous.wallet

Your wallet identifies you to this site and to nothing else. It is the only
thing here that knows who you are, and [what that gets you](/privacy) is one
address and a session id.

## unfunded.what

{symbol} is minted by this site, on devnet, and is worth nothing. It exists so
the meter has something real to move.

## unfunded.faucet

The faucet gives {demo} {symbol} and {sol} SOL, once per wallet. The SOL is for
the rent on your contract account and the fees; the site pays for both, and
this one does not go through your wallet.

## unfunded.spent

This wallet has already had its one grant, and the balance is {balance}
{symbol}. That is §13.2's walkthrough rather than a fault — the faucet is
stingy on purpose so a depleted balance is reachable.

## set_meter.cost

Each article costs {page_price} {symbol}. You authorize a ceiling; the site
draws against it as you read, and settles in batches rather than per article.

## set_meter.trust

The limit is trust, not pacing: a site can draw straight to it whenever it
likes. Better to meet that fact here, where the money is fake.

## failed.balance

Your balance is short by {short} {symbol}. On a real site this is where it
would say "top up"; here the faucet is the top-up, if this wallet has not
already had its one grant.

## failed.dead_end

This wallet has had its one grant, so there is no top-up here. What is left is
[the meter](/meter): close the contract, and this site forgets you. Closing
forgives what you are carrying rather than collecting it.

## failed.allowance

The amount you approved no longer covers what is owed — short by {short}
{symbol}. Renewing re-approves.

## failed.delegate

This site is no longer a delegate on your token account — the approval was
revoked, or SPL cleared it when the approved amount reached zero. Renewing
re-approves.

## failed.nothing_charged

Nothing was charged for this page. Transaction logs are not copied into this
site's own logs (§8.1) — if you want to read them, they are yours, on the
explorer.

## limit.used

Used {used} of {limit} {symbol}, of which {paid} has settled and {unpaid} has
not.

## limit.exit

[Raise the limit, or close it](/meter). Closing forgives the unpaid residue and
erases what this site holds about you.
