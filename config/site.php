<?php

declare(strict_types=1);

/**
 * Static configuration: everything that is a decision rather than a result.
 *
 * The counterpart is `var/site.json`, written by first-run setup (SPEC §12.0)
 * and holding what only a chain interaction can produce — the mint, the
 * treasury, the site PDA. Nothing here is generated and nothing here is
 * secret, so this file is committed and that one is not.
 *
 * Every amount is in base units, which is the only form the program sees.
 * The DEMO column in SPEC §4.2 is six decimals applied to these.
 */

use SolPay\Core\Ids;

return [
    // SPEC §12.4. The endpoint is a config value rather than an
    // architectural one: a provider tier is a change to this line.
    'rpc' => [
        'url' => 'https://api.devnet.solana.com',
        'commitment' => 'confirmed',
        // SPEC §7.3's bounded window. Past it the demo serves the article and
        // flags the request unconfirmed rather than charging a reader for
        // nothing.
        'confirm_timeout_ms' => 20_000,
        'confirm_poll_ms' => 500,
        'http_timeout_s' => 20,
    ],

    // SPEC §11: one deployment, one SPL Token mint. `Program::default()` is
    // this pair; it is spelled out so a local deployment is a config change.
    'program' => [
        'id' => Ids::PAY_ON_CHAIN_ID,
        'token_program' => Ids::TOKEN_PROGRAM_ID,
    ],

    // SPEC §4.1 and §4.2. `min_limit` > `collection_threshold` is a program
    // requirement; the ratio to `page_price` is the sol-pay README's advice.
    'site' => [
        'symbol' => 'DEMO',
        'decimals' => 6,
        'page_price' => 10_000,           // 0.01 DEMO
        'collection_threshold' => 100_000, // 0.10 DEMO — settles on the tenth view
        'min_limit' => 500_000,            // 0.50 DEMO — fifty views

        // Metaplex Token Metadata, written once by `bin/name-the-mint` so a
        // wallet's approval screen names the token instead of saying
        // "Unknown". Nothing on the metering path reads these.
        'token_name' => 'Newsprint DEMO',
        // Deliberately empty: it would point at a JSON file describing the
        // token, and §10.3 leaves this site nowhere to host one it approves
        // of. A wallet shows the name and symbol without it.
        'token_uri' => '',
    ],

    // SPEC §4.3. Stingy on purpose: a generous faucet makes §13.2's
    // balance_short walkthrough unreachable and quietly deletes one of the
    // two failure modes the demo exists to show.
    'faucet' => [
        'sol_lamports' => 50_000_000, // 0.05 SOL
        'demo_base_units' => 600_000, // 0.60 DEMO
    ],

    // SPEC §12.0's first run. The operator funds one address and setup moves
    // a reserve to the other, so there is one thing to do by hand and not two.
    'setup' => [
        'airdrop_lamports' => 1_000_000_000,        // 1 SOL; devnet's faucet refuses more often than it works
        'authority_minimum_lamports' => 300_000_000, // enough for setup's transactions and a long session of metering
        'faucet_reserve_lamports' => 250_000_000,    // four visitors: §4.3's 0.05 SOL each, plus the rent on a
                                                     // 165-byte token account and a fee. bin/devnet-canary
                                                     // computes it; this comment used to say five.
    ],

    // SPEC §5. Sign In With Solana, required with no fallback: a wallet
    // without the feature is refused by name rather than failed obscurely.
    'auth' => [
        // The Wallet Standard chain identifier, and the string the wallet
        // echoes into the signed message as "Chain ID". The type is an
        // unconstrained string in the specification, so this is a value the
        // two sides must agree on rather than one the format dictates; it
        // matches what `signAndSendTransaction` is given, which is the
        // agreement worth having.
        'chain_id' => 'solana:devnet',
        // What the wallet shows the reader. Kept to one sentence: a statement
        // nobody reads is a consent nobody gave.
        'statement' => 'Sign in to Newsprint. This proves you hold this wallet. It authorizes nothing and moves no money.',
        // Five minutes to complete the wallet dialog. Long enough for a first
        // approval on a phone, short enough that an abandoned challenge is
        // not a replay window.
        'challenge_ttl_s' => 300,
        // The server side of the session. The cookie itself is a session
        // cookie with no expiry (§5), so this is what bounds it.
        'session_ttl_s' => 43_200, // twelve hours
    ],

    // SPEC §7.1 and §7.4. Both are policy numbers with no chain meaning.
    'metering' => [
        'grant_ttl_s' => 1_800, // thirty minutes
        'demo_step_views' => 7, // below the ten-view threshold, so the settle is intermittent

        // When the article shell's second line appears (`assets/read-on.js`):
        // only once the wait is longer than usual. "Usual" is measured, not
        // guessed — ten charging views across four HARs, 2026-09-09 to 09-11,
        // took 3.0, 3.1, 4.1, 4.4, 4.8, 5.2, 6.7, 7.5, 8.2 and 9.6 s, median
        // about 5 s. At 7 s the line shows on three of those ten, and never on
        // a reader bound for `set_meter`, whose answer is one call and about a
        // second. Six sequential round trips set this, so it moves with the
        // endpoint: re-measure after changing `rpc.url` or its tier.
        'long_wait_ms' => 7_000,
    ],
];
