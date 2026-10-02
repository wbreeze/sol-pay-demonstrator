<?php

declare(strict_types=1);

namespace Newsprint\Pay;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode as Encoder;
use chillerlan\QRCode\QROptions;

/**
 * SPEC §6.3 step 3: the Solana Pay link as a code a phone can scan, drawn by
 * the server as inline SVG so that the page loads nothing more (§10.3, §12.2).
 *
 * `chillerlan/php-qrcode`, chosen 2026-10-01: MIT, no network, no extension
 * beyond `mbstring`, and SVG output that needs no image library.
 *
 * Error correction is M, the level most Solana Pay codes use: a link of about
 * eighty characters stays a small code, with room for a scuffed screen. The
 * quiet zone is kept, because a code without one beside other ink is the
 * commonest reason a phone will not read it. Dark modules on a light ground,
 * in either colour scheme, because scanners expect that polarity.
 */
final class QrCode
{
    public static function svg(string $link): string
    {
        $options = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => false,
            'svgAddXmlHeader' => false,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'drawLightModules' => false,
            'connectPaths' => true,
            'cssClass' => 'qr-code',
        ]);

        return (string) (new Encoder($options))->render($link);
    }
}
