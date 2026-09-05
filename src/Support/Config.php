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
 */
final class Config
{
    /**
     * @param array<string, mixed> $static
     * @param array<string, string>|null $provisioned
     */
    private function __construct(
        public readonly string $root,
        private readonly array $static,
        private ?array $provisioned,
    ) {
    }

    public static function load(string $root): self
    {
        /** @var array<string, mixed> $static */
        $static = require $root.'/config/site.php';

        $provisioned = null;
        $path = $root.'/var/site.json';
        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                $provisioned = $decoded;
            }
        }

        return new self($root, $static, $provisioned);
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
            throw new \RuntimeException('not provisioned: run first-run setup (SPEC §12.0)');
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
            $this->root.'/var/site.json',
            json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );
        $this->provisioned = $merged;
    }

    public function dbPath(): string
    {
        $this->ensureVar();

        return $this->root.'/var/newsprint.sqlite';
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

        return $this->root.'/var/'.$role.'.json';
    }

    private function ensureVar(): void
    {
        $dir = $this->root.'/var';
        if (!is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }
    }
}
