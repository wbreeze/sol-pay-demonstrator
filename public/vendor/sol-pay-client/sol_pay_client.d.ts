/* tslint:disable */
/* eslint-disable */

/**
 * A hash; the 32-byte output of a hashing algorithm.
 *
 * This struct is used most often in `solana-sdk` and related crates to contain
 * a [SHA-256] hash, but may instead contain a [blake3] hash.
 *
 * [SHA-256]: https://en.wikipedia.org/wiki/SHA-2
 * [blake3]: https://github.com/BLAKE3-team/BLAKE3
 */
export class Hash {
    free(): void;
    [Symbol.dispose](): void;
    /**
     * Create a new Hash object
     *
     * * `value` - optional hash as a base58 encoded string, `Uint8Array`, `[number]`
     */
    constructor(value: any);
    /**
     * Checks if two `Hash`s are equal
     */
    equals(other: Hash): boolean;
    /**
     * Return the `Uint8Array` representation of the hash
     */
    toBytes(): Uint8Array;
    /**
     * Return the base58 string representation of the hash
     */
    toString(): string;
}

/**
 * wasm-bindgen version of the Instruction struct.
 * This duplication is required until https://github.com/rustwasm/wasm-bindgen/issues/3671
 * is fixed. This must not diverge from the regular non-wasm Instruction struct.
 */
export class Instruction {
    private constructor();
    free(): void;
    [Symbol.dispose](): void;
}

export class Instructions {
    free(): void;
    [Symbol.dispose](): void;
    constructor();
    push(instruction: Instruction): void;
}

/**
 * One deployment of the metering program, the token program the site's
 * mint belongs to, and everything that depends on either.
 *
 * ```js
 * const pay = new PayOnChain();                    // canonical, SPL Token
 * const pay = new PayOnChain(myProgramId);         // my own deployment
 * const pay = new PayOnChain().withTokenProgram(   // a Token-2022 mint
 *   token2022ProgramAddress(),
 * );
 * ```
 *
 * Both are defaults, not constraints: a site that deploys its own copy of
 * the metering program passes that address here, and every derivation,
 * instruction and error name follows it. Nothing is verified -- an
 * address with no program behind it builds perfectly good instructions
 * that fail at the runtime. For the token program there is a cheap check,
 * `ownsMint`.
 */
export class PayOnChain {
    free(): void;
    [Symbol.dispose](): void;
    approveAndOpen(payer_token_account: string, mint: string, payer: string, site: string, limit: bigint, decimals: number): any;
    approveAndRenew(payer_token_account: string, mint: string, payer: string, site: string, new_limit: bigint, decimals: number): any;
    /**
     * Authorize the contract PDA to pull up to `amount`. Put this
     * *before* `openContract` or `renewContract` in the same
     * transaction.
     */
    approveChecked(payer_token_account: string, mint: string, payer: string, site: string, amount: bigint, decimals: number): any;
    /**
     * Name a failure, given the program that raised it and its code. The
     * program id matters: the same number means different things.
     *
     * `raisedBy` is matched against *this instance's* address, so a site
     * on its own deployment gets its own errors named rather than
     * reported as unknown.
     */
    cause(raised_by: string, code: number): any;
    closeAndRevoke(payer_token_account: string, payer: string, site: string): any;
    closeContract(site: string, payer: string): any;
    deriveContractAddress(site: string, payer: string): string;
    deriveSiteAddress(authority: string): string;
    initializeSite(authority: string, mint: string, treasury: string, page_price: bigint, collection_threshold: bigint, min_limit: bigint): any;
    meterAndSettle(site: string, authority: string, payer: string, payer_token_account: string, treasury: string, mint: string, page_views: number): any;
    /**
     * Pass nothing for the deployment this package was built against.
     */
    constructor(program_id?: string | null);
    openContract(site: string, payer: string, payer_token_account: string, limit: bigint): any;
    /**
     * Whether a mint account belongs to this instance's token program.
     * Pass the `owner` that came back beside the mint data from
     * `getAccountInfo`. A `false` here means every instruction this
     * instance builds for that mint will fail at the runtime.
     */
    ownsMint(mint_account_owner: string): boolean;
    renewContract(site: string, payer: string, payer_token_account: string, new_limit: bigint): any;
    /**
     * Withdraw the authorization. Worth pairing with `closeContract`.
     */
    revoke(payer_token_account: string, payer: string): any;
    /**
     * The same deployment, against a different token program. Returns a
     * new instance; this one is unchanged.
     */
    withTokenProgram(token_program: string): PayOnChain;
    /**
     * The address this instance builds for.
     */
    readonly programAddress: string;
    /**
     * The token program this instance builds against.
     */
    readonly tokenProgram: string;
}

/**
 * The address of a [Solana account][acc].
 *
 * Some account addresses are [ed25519] public keys, with corresponding secret
 * keys that are managed off-chain. Often, though, account addresses do not
 * have corresponding secret keys &mdash; as with [_program derived
 * addresses_][pdas] &mdash; or the secret key is not relevant to the operation
 * of a program, and may have even been disposed of. As running Solana programs
 * can not safely create or manage secret keys, the full [`Keypair`] is not
 * defined in `solana-program` but in `solana-sdk`.
 *
 * [acc]: https://solana.com/docs/core/accounts
 * [ed25519]: https://ed25519.cr.yp.to/
 * [pdas]: https://solana.com/docs/core/cpi#program-derived-addresses
 * [`Keypair`]: https://docs.rs/solana-sdk/latest/solana_sdk/signer/keypair/struct.Keypair.html
 */
export class Pubkey {
    free(): void;
    [Symbol.dispose](): void;
    /**
     * Create a new Pubkey object
     *
     * * `value` - optional public key as a base58 encoded string, `Uint8Array`, `[number]`
     */
    constructor(value: any);
    /**
     * Derive a program address from seeds and a program id
     */
    static createProgramAddress(seeds: any[], program_id: Pubkey): Pubkey;
    /**
     * Derive a Pubkey from another Pubkey, string seed, and a program id
     */
    static createWithSeed(base: Pubkey, seed: string, owner: Pubkey): Pubkey;
    /**
     * Checks if two `Pubkey`s are equal
     */
    equals(other: Pubkey): boolean;
    /**
     * Find a valid program address
     *
     * Returns:
     * * `[PubKey, number]` - the program address and bump seed
     */
    static findProgramAddress(seeds: any[], program_id: Pubkey): any;
    /**
     * Check if a `Pubkey` is on the ed25519 curve.
     */
    isOnCurve(): boolean;
    /**
     * Return the `Uint8Array` representation of the public key
     */
    toBytes(): Uint8Array;
    /**
     * Return the base58 string representation of the public key
     */
    toString(): string;
}

/**
 * `null` when the call would succeed; otherwise why it would not.
 */
export function canMeter(site_data: Uint8Array, contract_data: Uint8Array, page_views: number): any;

/**
 * What `pageViews` costs, or an error if it does not fit in u64.
 */
export function charge(site_data: Uint8Array, page_views: number): bigint;

/**
 * Decode a `Contract` account fetched with `getAccountInfo`.
 */
export function decodeContract(data: Uint8Array): any;

/**
 * Decode a `Site` account fetched with `getAccountInfo`.
 */
export function decodeSite(data: Uint8Array): any;

/**
 * Which constraint on the payer's token account is short, and by how
 * much. SPL reports a short balance and a short allowance identically,
 * so this reads the account rather than guessing from the code.
 *
 * Deployment-independent: it reads an SPL token account, and the amount
 * it compares against is one the caller already has.
 */
export function diagnose(token_account_data: Uint8Array, unpaid: bigint): any;

/**
 * Base units back to a decimal string, without trailing zeros.
 */
export function fromBaseUnits(units_: bigint, decimals: number): string;

/**
 * The smallest limit this payer may authorize. Pass the contract data
 * when renewing, and nothing when opening.
 */
export function limitFloor(site_data: Uint8Array, contract_data?: Uint8Array | null): bigint;

/**
 * The mint's decimals, which `approveChecked` needs.
 */
export function mintDecimals(mint_account_data: Uint8Array): number;

/**
 * Human amount to base units. Takes a string, not a number: `0.1` is not
 * representable in binary floating point.
 */
export function toBaseUnits(amount: string, decimals: number): bigint;

/**
 * The Token-2022 program, for passing to `withTokenProgram` without
 * hardcoding a base58 string.
 */
export function token2022ProgramAddress(): string;

/**
 * The SPL Token program. A `PayOnChain` uses this unless told otherwise.
 */
export function tokenProgramAddress(): string;

/**
 * How many more views fit under the limit.
 */
export function viewsRemaining(site_data: Uint8Array, contract_data: Uint8Array): bigint;

/**
 * Whether this call would also move money.
 */
export function willSettle(site_data: Uint8Array, contract_data: Uint8Array, page_views: number): boolean;

export type InitInput = RequestInfo | URL | Response | BufferSource | WebAssembly.Module;

export interface InitOutput {
    readonly memory: WebAssembly.Memory;
    readonly tokenProgramAddress: () => [number, number];
    readonly token2022ProgramAddress: () => [number, number];
    readonly decodeSite: (a: number, b: number) => [number, number, number];
    readonly decodeContract: (a: number, b: number) => [number, number, number];
    readonly mintDecimals: (a: number, b: number) => [number, number, number];
    readonly toBaseUnits: (a: number, b: number, c: number) => [bigint, number, number];
    readonly fromBaseUnits: (a: bigint, b: number) => [number, number];
    readonly charge: (a: number, b: number, c: number) => [bigint, number, number];
    readonly canMeter: (a: number, b: number, c: number, d: number, e: number) => [number, number, number];
    readonly willSettle: (a: number, b: number, c: number, d: number, e: number) => [number, number, number];
    readonly viewsRemaining: (a: number, b: number, c: number, d: number) => [bigint, number, number];
    readonly limitFloor: (a: number, b: number, c: number, d: number) => [bigint, number, number];
    readonly diagnose: (a: number, b: number, c: bigint) => [number, number, number];
    readonly __wbg_payonchain_free: (a: number, b: number) => void;
    readonly payonchain_new: (a: number, b: number) => [number, number, number];
    readonly payonchain_programAddress: (a: number) => [number, number];
    readonly payonchain_tokenProgram: (a: number) => [number, number];
    readonly payonchain_withTokenProgram: (a: number, b: number, c: number) => [number, number, number];
    readonly payonchain_ownsMint: (a: number, b: number, c: number) => [number, number, number];
    readonly payonchain_deriveSiteAddress: (a: number, b: number, c: number) => [number, number, number, number];
    readonly payonchain_deriveContractAddress: (a: number, b: number, c: number, d: number, e: number) => [number, number, number, number];
    readonly payonchain_cause: (a: number, b: number, c: number, d: number) => [number, number, number];
    readonly payonchain_approveAndOpen: (a: number, b: number, c: number, d: number, e: number, f: number, g: number, h: number, i: number, j: bigint, k: number) => [number, number, number];
    readonly payonchain_approveAndRenew: (a: number, b: number, c: number, d: number, e: number, f: number, g: number, h: number, i: number, j: bigint, k: number) => [number, number, number];
    readonly payonchain_closeAndRevoke: (a: number, b: number, c: number, d: number, e: number, f: number, g: number) => [number, number, number];
    readonly payonchain_initializeSite: (a: number, b: number, c: number, d: number, e: number, f: number, g: number, h: bigint, i: bigint, j: bigint) => [number, number, number];
    readonly payonchain_approveChecked: (a: number, b: number, c: number, d: number, e: number, f: number, g: number, h: number, i: number, j: bigint, k: number) => [number, number, number];
    readonly payonchain_revoke: (a: number, b: number, c: number, d: number, e: number) => [number, number, number];
    readonly payonchain_openContract: (a: number, b: number, c: number, d: number, e: number, f: number, g: number, h: bigint) => [number, number, number];
    readonly payonchain_meterAndSettle: (a: number, b: number, c: number, d: number, e: number, f: number, g: number, h: number, i: number, j: number, k: number, l: number, m: number, n: number) => [number, number, number];
    readonly payonchain_renewContract: (a: number, b: number, c: number, d: number, e: number, f: number, g: number, h: bigint) => [number, number, number];
    readonly payonchain_closeContract: (a: number, b: number, c: number, d: number, e: number) => [number, number, number];
    readonly __wbg_instructions_free: (a: number, b: number) => void;
    readonly instructions_constructor: () => number;
    readonly instructions_push: (a: number, b: number) => void;
    readonly __wbg_instruction_free: (a: number, b: number) => void;
    readonly __wbg_pubkey_free: (a: number, b: number) => void;
    readonly pubkey_constructor: (a: any) => [number, number, number];
    readonly pubkey_toString: (a: number) => [number, number];
    readonly pubkey_isOnCurve: (a: number) => number;
    readonly pubkey_equals: (a: number, b: number) => number;
    readonly pubkey_toBytes: (a: number) => [number, number];
    readonly pubkey_createWithSeed: (a: number, b: number, c: number, d: number) => [number, number, number];
    readonly pubkey_createProgramAddress: (a: number, b: number, c: number) => [number, number, number];
    readonly pubkey_findProgramAddress: (a: number, b: number, c: number) => [number, number, number];
    readonly __wbg_hash_free: (a: number, b: number) => void;
    readonly hash_constructor: (a: any) => [number, number, number];
    readonly hash_toString: (a: number) => [number, number];
    readonly hash_equals: (a: number, b: number) => number;
    readonly hash_toBytes: (a: number) => [number, number];
    readonly __wbindgen_malloc: (a: number, b: number) => number;
    readonly __wbindgen_realloc: (a: number, b: number, c: number, d: number) => number;
    readonly __wbindgen_exn_store: (a: number) => void;
    readonly __externref_table_alloc: () => number;
    readonly __wbindgen_externrefs: WebAssembly.Table;
    readonly __externref_table_dealloc: (a: number) => void;
    readonly __wbindgen_free: (a: number, b: number, c: number) => void;
    readonly __wbindgen_start: () => void;
}

export type SyncInitInput = BufferSource | WebAssembly.Module;

/**
 * Instantiates the given `module`, which can either be bytes or
 * a precompiled `WebAssembly.Module`.
 *
 * @param {{ module: SyncInitInput }} module - Passing `SyncInitInput` directly is deprecated.
 *
 * @returns {InitOutput}
 */
export function initSync(module: { module: SyncInitInput } | SyncInitInput): InitOutput;

/**
 * If `module_or_path` is {RequestInfo} or {URL}, makes a request and
 * for everything else, calls `WebAssembly.instantiate` directly.
 *
 * @param {{ module_or_path: InitInput | Promise<InitInput> }} module_or_path - Passing `InitInput` directly is deprecated.
 *
 * @returns {Promise<InitOutput>}
 */
export default function __wbg_init (module_or_path?: { module_or_path: InitInput | Promise<InitInput> } | InitInput | Promise<InitInput>): Promise<InitOutput>;
