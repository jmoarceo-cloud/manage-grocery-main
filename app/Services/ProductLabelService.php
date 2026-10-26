<?php

namespace App\Services;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class ProductLabelService
{
    public static function ean13(string $barcode): ?string
    {
        if (! preg_match('/\A[0-9]{13}\z/', $barcode)) {
            return null;
        }
        $sum = 0;
        for ($index = 0; $index < 12; $index++) {
            $sum += (int) $barcode[$index] * ($index % 2 === 0 ? 1 : 3);
        }
        if ((10 - $sum % 10) % 10 !== (int) $barcode[12]) {
            return null;
        }
        // Preserve the legacy EAN-13 encodings, adding quiet zones and checksum validation.
        $odd = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];
        $even = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];
        $right = ['1110010', '1100110', '1101100', '1000010', '1011100', '1001110', '1010000', '1000100', '1001000', '1110100'];
        $parity = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];
        $bits = '101';
        $pattern = $parity[(int) $barcode[0]];
        for ($index = 1; $index <= 6; $index++) {
            $bits .= $pattern[$index - 1] === 'L' ? $odd[(int) $barcode[$index]] : $even[(int) $barcode[$index]];
        }
        $bits .= '01010';
        for ($index = 7; $index <= 12; $index++) {
            $bits .= $right[(int) $barcode[$index]];
        }
        $bits .= '101';
        $bars = '';
        for ($index = 0; $index < 95; $index++) {
            if ($bits[$index] !== '1') {
                continue;
            }
            $guard = $index < 3 || ($index >= 45 && $index < 50) || $index >= 92;
            $bars .= '<rect x="'.($index + 11).'" y="3" width="1" height="'.($guard ? 38 : 33).'"/>';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="351" height="156" viewBox="0 0 117 52" role="img" aria-label="EAN-13 '.$barcode.'"><rect width="117" height="52" fill="white"/><g fill="black">'.$bars.'</g><text x="58.5" y="49" text-anchor="middle" font-family="Arial,sans-serif" font-size="8">'.$barcode.'</text></svg>';
    }

    public static function qr(string $url): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(256, 4), new SvgImageBackEnd));
        $svg = $writer->writeString($url, 'UTF-8', ErrorCorrectionLevel::H());

        return preg_replace('/\A<\?xml[^>]+\?>\s*/', '', $svg);
    }
}
