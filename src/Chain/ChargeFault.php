<?php

declare(strict_types=1);

namespace Newsprint\Chain;

/**
 * A fault put into the article charge on purpose, to reach the two outcomes
 * of SPEC §7.3 that devnet will not produce by itself (2026-09-17).
 *
 * Serving first means an article charge can land and fail, or never land,
 * after the reader already has the article. On devnet neither happens when
 * you want it to: the endpoint's simulation refuses the charges that would
 * fail, and the rest confirm in under a second. So this makes them happen.
 *
 *     NEWSPRINT_CHARGE_FAULT=fail-after-serving bin/run-dev
 *     NEWSPRINT_CHARGE_FAULT=never-land bin/run-dev
 *
 * - **`fail-after-serving`** sends the charge with `skipPreflight`. A charge
 *   the endpoint would have refused — a balance too short for the settle,
 *   say — is forwarded instead, lands and fails, and the reader sees the
 *   absorbed report. The site pays the fee, in devnet SOL.
 * - **`never-land`** also skips the simulation, and compiles the charge
 *   against a blockhash the cluster has never issued. The validators drop it,
 *   so the follow-up's window closes without an answer, and a request after
 *   `charge_settle_s` finds it never landed.
 *
 * **Devnet only, and loudly.** Set against any endpoint whose host does not
 * say `devnet`, or set to a word this does not know, it throws rather than
 * running the site without the fault the tester thinks is on. The article
 * charge is the only thing it touches; the advance and every transaction a
 * wallet sends are unaffected. The inspector's deployment section says when
 * it is on, so a screenshot cannot pass it off as ordinary behaviour.
 */
enum ChargeFault: string
{
    case None = '';
    case FailAfterServing = 'fail-after-serving';
    case NeverLand = 'never-land';

    public const VARIABLE = 'NEWSPRINT_CHARGE_FAULT';

    public static function fromEnvironment(string $rpcUrl): self
    {
        $value = getenv(self::VARIABLE);
        if ($value === false || $value === '') {
            return self::None;
        }

        $fault = self::tryFrom($value);
        if ($fault === null) {
            throw new \RuntimeException(sprintf(
                '%s=%s is not a fault this site knows; use %s or %s',
                self::VARIABLE,
                $value,
                self::FailAfterServing->value,
                self::NeverLand->value,
            ));
        }

        $host = (string) parse_url($rpcUrl, PHP_URL_HOST);
        if (!str_contains($host, 'devnet')) {
            throw new \RuntimeException(sprintf(
                '%s is set, and the endpoint %s is not devnet; refusing to break charges anywhere else',
                self::VARIABLE,
                $rpcUrl,
            ));
        }

        return $fault;
    }

    /** Whether the endpoint's simulation is skipped. Both faults need it: the simulation would refuse either one. */
    public function skipsPreflight(): bool
    {
        return $this !== self::None;
    }
}
