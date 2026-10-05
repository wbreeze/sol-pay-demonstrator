<?php

declare(strict_types=1);

namespace Newsprint\Setup;

use Newsprint\Chain\Keypair;
use Newsprint\Chain\Rpc;
use Newsprint\Chain\RpcException;
use Newsprint\Chain\Submitter;
use Newsprint\Chain\SystemProgram;
use Newsprint\Support\Config;

/**
 * New keys for a site that already has them (SPEC §12.6, §15.4).
 *
 * **A roll abandons the site, and nothing here can soften that.** The site
 * account is derived from the authority, so a new authority is a new site.
 * Every meter open at the old site stays open. Only its reader, or the browser
 * key it names, may close it: the authority may not, so there is no cleanup a
 * roll could run on a reader's behalf. `bin/setup --roll` says so and asks
 * before it calls this.
 *
 * **What a roll can save is the SOL.** The old authority and the old faucet
 * key can still sign, so their balances move to the new authority before the
 * old files stop being used. Setup then finds the new authority funded and
 * asks devnet's faucet for nothing.
 *
 * **Set aside, not deleted.** The old files move into a dated directory
 * beside the new ones. The old authority is the only key that can still settle
 * an old meter, and a balance that failed to move is still spendable by the
 * file that holds it. Deleting the directory later is the operator's decision.
 */
final class KeyRoll
{
    /**
     * A transaction with one signature pays this, in lamports. It is taken off
     * the balance so that the transfer empties the account exactly: an account
     * left holding less than rent exemption is refused by the cluster.
     */
    private const FEE_LAMPORTS = 5_000;

    /** What one site's directory holds: three keys, and the addresses setup recorded. */
    private const FILES = ['authority.json', 'faucet.json', 'mint.json', 'site.json'];

    /**
     * Move the site's files into `rolled-<stamp>/` under the same directory.
     *
     * @return string the directory they went to
     *
     * @throws \RuntimeException when there is no provisioned site to roll, or
     *                           a file would not move
     */
    public static function setAside(Config $config, string $stamp): string
    {
        if (!$config->isProvisioned()) {
            throw new \RuntimeException('there is no provisioned site here to roll; bin/setup makes one');
        }

        $from = $config->varDir();
        $aside = $from.'/rolled-'.$stamp;
        if (is_dir($aside)) {
            throw new \RuntimeException("{$aside} already exists; a roll was set aside there this second");
        }
        mkdir($aside, 0o700);

        foreach (self::FILES as $file) {
            // The mint's keypair is absent from a host, which never needs it.
            if (is_file($from.'/'.$file) && !rename($from.'/'.$file, $aside.'/'.$file)) {
                throw new \RuntimeException("could not move {$file} into {$aside}");
            }
        }

        return $aside;
    }

    /**
     * Send what the old authority and the old faucet key hold to `$to`.
     *
     * A balance that does not move is reported and left where it is. It is
     * devnet SOL, and the roll is not worth stopping for it.
     *
     * @return list<Step>
     */
    public static function moveSol(string $aside, string $to, Rpc $rpc, Submitter $submitter): array
    {
        $steps = [];

        foreach (['authority', 'faucet'] as $role) {
            $name = "old {$role}";
            $path = "{$aside}/{$role}.json";
            if (!is_file($path)) {
                continue;
            }
            $old = Keypair::load($path);

            try {
                $lamports = $rpc->balance($old->address) - self::FEE_LAMPORTS;
                if ($lamports <= 0) {
                    $steps[] = Step::already($name, 'It holds nothing worth a fee to move.', $old->address);
                    continue;
                }
                $outcome = $submitter->send([SystemProgram::transfer($old->address, $to, $lamports)], $old);
            } catch (RpcException $e) {
                $steps[] = Step::failed($name, "Its SOL was not moved: {$e->getMessage()}. The key is in {$aside}.");
                continue;
            }

            $steps[] = $outcome->ok()
                ? Step::done($name, sprintf('%s SOL moved to the new authority.', self::sol($lamports)), $outcome->signature, $old->address)
                : Step::failed($name, "Its SOL was not moved: {$outcome->detail}. The key is in {$aside}.", $outcome->signature);
        }

        return $steps;
    }

    private static function sol(int $lamports): string
    {
        return rtrim(rtrim(number_format($lamports / 1_000_000_000, 9, '.', ''), '0'), '.');
    }
}
