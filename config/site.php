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
    ],

    // SPEC §4.3. Stingy on purpose: a generous faucet makes §13.2's
    // balance_short walkthrough unreachable and quietly deletes one of the
    // two failure modes the demo exists to show.
    'faucet' => [
        'sol_lamports' => 50_000_000, // 0.05 SOL
        'demo_base_units' => 600_000, // 0.60 DEMO
    ],

    // SPEC §7.1 and §7.4. Both are policy numbers with no chain meaning.
    'metering' => [
        'grant_ttl_s' => 1_800, // thirty minutes
        'demo_step_views' => 7, // below the ten-view threshold, so the settle is intermittent
    ],
];
