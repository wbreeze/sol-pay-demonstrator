<?php

declare(strict_types=1);

namespace Newsprint\Tests\Metering;

use Newsprint\Chain\Failure;
use Newsprint\Chain\Outcome;
use Newsprint\Chain\RpcException;
use Newsprint\Metering\ChargeFinisher;
use Newsprint\Metering\ChargeState;
use Newsprint\Store\Database;
use Newsprint\Store\Store;
use PHPUnit\Framework\TestCase;

/**
 * SPEC §7.3's second half (2026-09-17): an article is served as soon as the
 * endpoint accepts its charge, and a later request finds out what became of
 * it.
 *
 * Each case is one branch of {@see ChargeFinisher::finish()}. The chain is a
 * closure, which also counts how often it is asked. Two properties run through
 * all of them and are asserted every time: **the grant is never removed**,
 * whatever the chain says, and **a settled grant costs no chain call**.
 */
final class ChargeFinisherTest extends TestCase
{
    private const SETTLE_S = 120;

    private int $now = 1_700_000_000;

    private int $asked = 0;

    private Store $store;

    protected function setUp(): void
    {
        $this->store = new Store(Database::open(':memory:'), fn (): int => $this->now);
        $this->store->recordGrant('PAYRfig', 'article-one', 1_800, 'sigone', ChargeState::Pending);
    }

    public function testNoGrantIsNoAnswerAndNoQuestion(): void
    {
        self::assertNull($this->finish('article-two', Outcome::confirmed('sigone')));
        self::assertSame(0, $this->asked);
    }

    public function testALandedChargeIsWrittenConfirmed(): void
    {
        $found = $this->finish('article-one', Outcome::confirmed('sigone'));

        self::assertSame(ChargeState::Confirmed, $found['charge'] ?? null);
        self::assertSame('sigone', $found['signature'] ?? null);
        self::assertSame(ChargeState::Confirmed, $this->charge());
        self::assertSame(1, $this->asked);
    }

    public function testASettledGrantIsNotAskedAboutAgain(): void
    {
        $this->finish('article-one', Outcome::confirmed('sigone'));
        $found = $this->finish('article-one', Outcome::failed('sigone', null));

        self::assertNotNull($found);
        self::assertSame(ChargeState::Confirmed, $found['charge'], 'the first answer stands');
        self::assertNull($found['outcome'], 'and no second question was put');
        self::assertSame(1, $this->asked);
    }

    /**
     * The policy the reader was promised: the article was served, so it stays
     * served. What changes is the word on the row.
     */
    public function testALandedFailureIsRefusedAndTheGrantStays(): void
    {
        $found = $this->finish('article-one', Outcome::failed('sigone', Failure::fromStatusError(['InstructionError' => [0, ['Custom' => 1]]])));

        self::assertSame(ChargeState::Refused, $found['charge'] ?? null);
        self::assertSame(ChargeState::Refused, $this->charge());
        self::assertNotNull($this->store->liveGrant('PAYRfig', 'article-one'), 'the reader keeps the article');
    }

    public function testNoAnswerInsideTheWindowStaysPending(): void
    {
        $this->now += self::SETTLE_S - 1;

        $found = $this->finish('article-one', Outcome::unconfirmed('sigone'));

        self::assertSame(ChargeState::Pending, $found['charge'] ?? null);
        self::assertSame(ChargeState::Pending, $this->charge(), 'it may yet land');
    }

    public function testNoAnswerPastTheWindowIsUnknownAndTheGrantStays(): void
    {
        $this->now += self::SETTLE_S;

        $found = $this->finish('article-one', Outcome::unconfirmed('sigone'));

        self::assertSame(ChargeState::Unknown, $found['charge'] ?? null);
        self::assertSame(ChargeState::Unknown, $this->charge());
        self::assertNotNull($this->store->liveGrant('PAYRfig', 'article-one'));
    }

    /** The endpoint's silence is not the chain's: nothing is decided on it, however late. */
    public function testAnEndpointThatDoesNotAnswerDecidesNothing(): void
    {
        $this->now += 10 * self::SETTLE_S;

        $found = (new ChargeFinisher($this->store, self::SETTLE_S))->finish(
            'PAYRfig',
            'article-one',
            function (string $signature): Outcome {
                $this->asked += 1;

                throw new RpcException('connection refused');
            },
        );

        self::assertNotNull($found);
        self::assertSame(ChargeState::Pending, $found['charge']);
        self::assertNull($found['outcome']);
        self::assertSame(ChargeState::Pending, $this->charge());
    }

    /**
     * A second charge for the same article renews the grant under a new
     * signature. News about the first must not be written onto the second.
     */
    public function testNewsAboutAnOlderChargeIsNotWrittenOntoANewerOne(): void
    {
        self::assertFalse($this->store->settleCharge('PAYRfig', 'article-one', 'sigold', ChargeState::Refused));
        self::assertSame(ChargeState::Pending, $this->charge());

        self::assertTrue($this->store->settleCharge('PAYRfig', 'article-one', 'sigone', ChargeState::Confirmed));
        self::assertFalse($this->store->settleCharge('PAYRfig', 'article-one', 'sigone', ChargeState::Refused), 'and only once');
        self::assertSame(ChargeState::Confirmed, $this->charge());
    }

    /** The closure is handed the grant's signature — the only one this class ever asks about. */
    public function testTheQuestionIsAboutTheGrantsOwnSignature(): void
    {
        $seen = null;
        (new ChargeFinisher($this->store, self::SETTLE_S))->finish(
            'PAYRfig',
            'article-one',
            static function (string $signature) use (&$seen): Outcome {
                $seen = $signature;

                return Outcome::confirmed($signature);
            },
        );

        self::assertSame('sigone', $seen);
    }

    /** @return array{charge: ChargeState, signature: ?string, outcome: ?Outcome}|null */
    private function finish(string $article, Outcome $answer): ?array
    {
        return (new ChargeFinisher($this->store, self::SETTLE_S))->finish(
            'PAYRfig',
            $article,
            function (string $signature) use ($answer): Outcome {
                $this->asked += 1;

                return $answer;
            },
        );
    }

    private function charge(): ?ChargeState
    {
        return $this->store->liveGrant('PAYRfig', 'article-one')['charge'] ?? null;
    }
}
