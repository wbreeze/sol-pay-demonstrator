<?php

declare(strict_types=1);

namespace Newsprint\Support;

use SolPay\Core\Program;

/**
 * Static configuration (`config/site.php`) plus whatever first-run setup
 * produced (`var/site.json`).
 *
 * The split is on provenance rather than on shape: a decision is committed, a
 * result of a chain interaction is not. SPEC §12.0 has setup generating the
 * second half on first run, so `isProvisioned()` is the question every entry
 * point asks before it does anything else.
 *
 * **The second half lives in one directory, and the directory can be named.**
 * It is `var/` for the site this checkout serves. `bin/setup --into` names
 * another, which is how the hosted instance's keys are made on the
 * development machine and kept apart from the local site's (SPEC §12.6).
 */
final class Config
{
    public const DEV_WALLET_VARIABLE = 'NEWSPRINT_DEV_WALLET';

    /**
     * @param array<string, mixed> $static
     * @param array<string, string>|null $provisioned
     */
    private function __construct(
        public readonly string $root,
        private readonly string $var,
        private readonly array $static,
        private ?array $provisioned,
    ) {
    }

    /**
     * @param string|null $var where the provisioned half lives. Null is this
     *                         checkout's own `var/`
     */
    public static function load(string $root, ?string $var = null): self
    {
        /** @var array<string, mixed> $static */
        $static = require $root.'/config/site.php';
        $var = rtrim($var ?? $root.'/var', '/');

        return new self($root, $var, $static, self::readJson($var.'/site.json'));
    }

    /**
     * The site's public base URL, with no trailing slash, or null where it
     * has none (SPEC §12.3).
     *
     * Only the hosted instance has one, and its address is a fact about that
     * machine and not about the repository. So `bin/host` writes it to
     * `var/host.json` beside the keys, and the tracked file stays null.
     */
    public function publicUrl(): ?string
    {
        $url = $this->static['public_url'] ?? null;
        if (!is_string($url) || $url === '') {
            $url = self::readJson($this->var.'/host.json')['public_url'] ?? null;
        }

        return is_string($url) && $url !== '' ? rtrim($url, '/') : null;
    }

    /**
     * Whether this copy is the hosted instance: the one other people's
     * wallets meet, and the one that may be taken down (SPEC §12.6).
     */
    public function isHosted(): bool
    {
        return $this->publicUrl() !== null;
    }

    /** The directory the provisioned half lives in. */
    public function varDir(): string
    {
        return $this->var;
    }

    public function rpcUrl(): string
    {
        return (string) $this->static['rpc']['url'];
    }

    /** @return array<string, mixed> */
    public function rpc(): array
    {
        return $this->static['rpc'];
    }

    public function program(): Program
    {
        return new Program(
            (string) $this->static['program']['id'],
            (string) $this->static['program']['token_program'],
        );
    }

    /** @return array<string, int|string> */
    public function siteParams(): array
    {
        return $this->static['site'];
    }

    /** @return array<string, int> */
    public function faucet(): array
    {
        return $this->static['faucet'];
    }

    /** @return array<string, int> */
    public function setup(): array
    {
        return $this->static['setup'];
    }

    /** @return array<string, int|string> */
    public function auth(): array
    {
        return $this->static['auth'];
    }

    /**
     * Development stand-ins, all off unless configured on. Absent from an
     * older `config/site.php`, which reads as all off.
     *
     * @return array<string, bool>
     */
    public function development(): array
    {
        $configured = $this->static['development'] ?? [];
        // `NEWSPRINT_DEV_WALLET=1` turns the development wallet on for one
        // process, as `NEWSPRINT_RPC_TIMING` does for timing. It can only
        // turn it on, and the route's own checks (loopback, devnet) still
        // apply (SPEC §12.6).
        if (getenv(self::DEV_WALLET_VARIABLE) === '1') {
            $configured['wallet'] = true;
        }

        return $configured;
    }

    /** @return array<string, int> */
    public function metering(): array
    {
        return $this->static['metering'];
    }

    /**
     * Provisioned means the site account exists, not that setup started.
     * `var/site.json` is written as each step finishes, so a run interrupted
     * after the mint but before `initialize_site` leaves a file behind — and a
     * file is not a site.
     */
    public function isProvisioned(): bool
    {
        return isset($this->provisioned['site']);
    }

    /**
     * Whatever setup has recorded so far, which may be nothing. This is what
     * makes a second run resume rather than start over: a mint that already
     * exists costs rent that is not worth paying twice.
     *
     * @return array<string, string>
     */
    public function partial(): array
    {
        return $this->provisioned ?? [];
    }

    /**
     * The addresses setup produced: authority, mint, treasury, site.
     *
     * @return array<string, string>
     *
     * @throws \RuntimeException before first-run setup has written them
     */
    public function provisioned(): array
    {
        if ($this->provisioned === null) {
            throw new \RuntimeException('not provisioned: run bin/setup (SPEC §12.0)');
        }

        return $this->provisioned;
    }

    /**
     * Merge into what setup has already recorded, and write it out.
     *
     * @param array<string, string> $addresses
     */
    public function writeProvisioned(array $addresses): void
    {
        $this->ensureVar();
        $merged = array_merge($this->provisioned ?? [], $addresses);
        file_put_contents(
            $this->var.'/site.json',
            json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );
        $this->provisioned = $merged;
    }

    public function dbPath(): string
    {
        $this->ensureVar();

        return $this->var.'/newsprint.sqlite';
    }

    /**
     * Where a key lives. SPEC §4.4 holds two — the site authority and the
     * faucet — and §15 is the open question of what custody a real deployment
     * owes them. Here they are files under `var/`, which is gitignored, and
     * they control nothing of value on devnet.
     */
    public function keypairPath(string $role): string
    {
        $this->ensureVar();

        return $this->var.'/'.$role.'.json';
    }

    private function ensureVar(): void
    {
        if (!is_dir($this->var)) {
            mkdir($this->var, 0o700, true);
        }
    }

    /** @return array<string, string>|null null when the file is absent or is not a JSON object */
    private static function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
