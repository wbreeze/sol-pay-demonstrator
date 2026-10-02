<?php

declare(strict_types=1);

namespace Newsprint\Tests\Pay;

use Newsprint\Pay\QrCode;
use PHPUnit\Framework\TestCase;

/**
 * The code is inlined into a JSON answer and then into the page, so it must be
 * bare SVG: no XML declaration, no base64 data URI, nothing fetched. That it
 * scans was checked by rendering it in Chromium in both colour schemes and
 * decoding the screenshots back to the link (2026-10-01), which needs a
 * browser and is not repeated here.
 */
final class QrCodeTest extends TestCase
{
    public function testTheCodeIsBareInlineSvg(): void
    {
        $svg = QrCode::svg('solana:https://example.test/pay/'.str_repeat('a', 32));

        self::assertStringStartsWith('<svg ', $svg);
        self::assertStringNotContainsString('<?xml', $svg);
        self::assertStringNotContainsString('data:', $svg);
        self::assertStringNotContainsString('href', $svg, 'nothing is fetched');
        self::assertMatchesRegularExpression('/viewBox="0 0 (\d+) \1"/', $svg, 'square, and scaled by the page');
        self::assertStringEndsWith('</svg>', trim($svg));
    }

    /** A longer link is a larger code, not a failure: the public URL sets the length (§12.3). */
    public function testALongPublicUrlStillEncodes(): void
    {
        $short = QrCode::svg('solana:http://localhost/pay/'.str_repeat('a', 32));
        $long = QrCode::svg('solana:https://newsprint.some-long-hostname.example.org/pay/'.str_repeat('a', 32));

        preg_match('/viewBox="0 0 (\d+)/', $short, $s);
        preg_match('/viewBox="0 0 (\d+)/', $long, $l);
        self::assertGreaterThanOrEqual((int) $s[1], (int) $l[1]);
    }
}
