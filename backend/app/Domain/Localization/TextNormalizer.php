<?php

namespace App\Domain\Localization;

use Normalizer;

/**
 * Canonical source-text normalization (package spec §5 step 2).
 * lugha-core (Rust) must produce byte-identical output: tests/Unit/TextNormalizerTest.php is the shared fixture set.
 */
final class TextNormalizer
{
    public static function normalize(string $text): string
    {
        $s = Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text;
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, [
            "\u{2018}" => "'", "\u{2019}" => "'", "\u{201C}" => '"', "\u{201D}" => '"',
            "\u{2013}" => '-', "\u{2014}" => '-', "\u{2026}" => '...',
        ]);
        $s = preg_replace('/\s+/u', ' ', $s);
        $s = trim($s);

        return rtrim($s, " .,!?;:\"'");
    }

    public static function hash(string $text): string
    {
        return hash('sha256', self::normalize($text));
    }
}
