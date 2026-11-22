<?php

namespace App\Domain\Invoicing;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\CarbonInterface;

/**
 * ZATCA e-invoicing, generation phase: the QR on simplified tax invoices and their credit notes is a
 * base64 TLV string of five fields — seller name, seller VAT number, timestamp, total incl. VAT, VAT total.
 * (The integration phase adds UBL XML, a cryptographic stamp and reporting via Fatoora; that needs the
 * merchant's ZATCA onboarding credentials and is not implemented here.)
 */
final class ZatcaQr
{
    public static function payload(string $sellerName, string $vatNumber, CarbonInterface $issuedAt, int $totalMinor, int $vatMinor): string
    {
        $fields = [
            1 => $sellerName,
            2 => $vatNumber,
            3 => $issuedAt->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            4 => number_format($totalMinor / 100, 2, '.', ''),
            5 => number_format($vatMinor / 100, 2, '.', ''),
        ];

        $tlv = '';
        foreach ($fields as $tag => $value) {
            if (strlen($value) > 255) {
                throw new \InvalidArgumentException("ZATCA TLV field $tag exceeds 255 bytes.");
            }
            $tlv .= chr($tag).chr(strlen($value)).$value; // length is in bytes (UTF-8), per the spec
        }

        return base64_encode($tlv);
    }

    /** @return array<int, string> tag => value (used by tests and support tooling) */
    public static function decode(string $base64): array
    {
        $bin = base64_decode($base64, true) ?: '';
        $out = [];
        for ($i = 0; $i < strlen($bin);) {
            $tag = ord($bin[$i]);
            $len = ord($bin[$i + 1]);
            $out[$tag] = substr($bin, $i + 2, $len);
            $i += 2 + $len;
        }

        return $out;
    }

    public static function svg(string $payload, int $size = 168): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd));

        return $writer->writeString($payload);
    }

    public static function enabled(): bool
    {
        return filled(config('lugha.seller.vat_number')) && filled(config('lugha.seller.name'));
    }
}
