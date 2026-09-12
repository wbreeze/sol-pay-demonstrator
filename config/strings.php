<?php

declare(strict_types=1);

/**
 * Everything the site says in a handful of words (SPEC §10.x).
 *
 * **Keys, never positions.** A numerically indexed catalogue is unreadable at
 * the call site — `$copy->line('meter.47')` tells you nothing about what is
 * about to appear on the page — and every insertion renumbers the rest of the
 * file, so a copy edit becomes a diff nobody can review. The keys here name
 * *where the words go*, never what they say: `meter.unfunded.heading` survives
 * a rewrite of the heading, and `meter.faucet_warning` would not.
 *
 * **Nested, and flattened on load.** Nesting is what keeps a file of three
 * hundred strings readable: a screen is a block you can review in one sitting
 * and move in one piece. The cost is that `grep meter.unfunded.heading` finds
 * the call site and not the string — which is why `CopyTest` asserts that
 * every key used exists and every key here is used. That test is the grep.
 *
 * **What belongs here and what does not.** Lines, not paragraphs: headings,
 * buttons, labels, and the short sentences that are part of the furniture.
 * Anything longer is prose and lives in `content/ui/`, where it can be edited
 * as writing rather than as a PHP string. Developer-facing text — exception
 * messages, `bin/` output — stays where it is thrown; it is not the site
 * speaking.
 *
 * **Markup is allowed and values are escaped.** The same reasoning as
 * `html_input => 'strip'` in `bin/build-content`, pointed the other way: this
 * file is the site's own, so a link in it is the site's own choice, while a
 * `{placeholder}` is filled from a request and is escaped on the way in. The
 * consequence is that the output of `Copy` is never passed through `View::e`,
 * and `CopyTest` checks that it is not.
 */

return [
    'meter' => [
        // Three stages share one heading. They used to share three copies of
        // it, which is the smallest possible version of the reason for this
        // file: a wording fixed in two places is a wording fixed in two
        // places.
        'gate' => ['heading' => 'The rest is metered'],

        'unprovisioned' => ['line' => 'This copy has not been set up yet. <a href="/setup">First run</a> creates the site on devnet.'],
        'unreadable' => ['line' => 'Currently unable to consult the meter.'],

        'anonymous' => ['button' => 'Connect a wallet'],

        'unfunded' => [
            'heading' => 'You will need some {symbol}',
            'button' => 'Send me {amount} {symbol}',
        ],

        'set_meter' => [
            'heading' => 'Set a limit',
            'wallet' => 'Paying wallet <code>{wallet}</code> · balance {balance} {symbol}',
            'label' => 'Limit',
            'floor' => 'The smallest limit you can set is {floor} {symbol}.',
            'button' => 'Authorize',
        ],

        'failed' => [
            'heading' => 'The charge did not go through',
            'said' => 'The chain said: <code>{cause}</code>',
            'renew' => '<a href="/meter">Renew the meter</a>',
        ],

        'limit' => ['heading' => 'You have reached your limit'],

        'running' => [
            'heading' => 'The meter is running',
            'line' => 'Limit {limit} {symbol} · used {used} · settled {paid} · {views} views left.',
        ],

        // §6's permanent exit, in the two wordings the comment in the template
        // explains: a reader who authorized has a contract, and one who has
        // not is owed §10.4's claim instead.
        'exit' => [
            'spent' => '<a href="/meter">The meter</a> — what you have spent, and the way out.',
            'held' => '<a href="/meter">The meter</a> — what this site is holding for you, and how to have it forgotten.',
        ],
    ],
];
