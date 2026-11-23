<?php

namespace App\Domain\Localization;

/**
 * Game text carries control codes the player never sees as text: button and map icons (~INPUT_SHOP_BUY|UrgentPulse~,
 * ~BLIP_GUN~), line breaks (~n~), colours (~s~, ~b~ …), subtitle timing (~sl:0.05~, ~tone~) and HTML-like tags.
 * Packages need the English as it appears on screen (what OCR reads) and the Arabic as it can be drawn.
 */
final class GameMarkup
{
    /** Drawn in place of a button icon in the Arabic (the game shows the controller button there). */
    public const BUTTON = '◆';

    public static function source(string $text): string
    {
        $text = preg_replace('/~n~/i', ' ', $text);
        $text = self::inline($text);
        $text = preg_replace('/~[^~\s]{0,80}~/', ' ', $text); // icons: not text on screen
        $text = preg_replace('/<\/?[A-Za-z][^<>]{0,40}>/', '', $text);

        $text = preg_replace('/ +([.,!?;:])/u', '$1', preg_replace('/[ \t]{2,}/', ' ', $text));

        return trim($text);
    }

    public static function target(string $text): string
    {
        $text = preg_replace('/~n~/i', "\n", $text);
        $text = self::inline($text);
        $text = preg_replace('/~INPUT_[^~\s]{0,80}~/', self::BUTTON, $text);
        $text = preg_replace('/~[^~\s]{0,80}~/', ' ', $text);
        $text = preg_replace('/<\/?[A-Za-z][^<>]{0,40}>/', '', $text);
        $text = preg_replace('/ +([.,!?;:،؟])/u', '$1', preg_replace('/[ \t]{2,}/', ' ', $text));

        return trim(preg_replace('/ *\n */', "\n", $text));
    }

    /** Colours and timing sit inside words and before punctuation: removed without leaving a space. */
    private static function inline(string $text): string
    {
        return preg_replace('/~(?:[a-z]|h[a-z]?|sl:[0-9.:]*|tone[^~\s]{0,20}|[0-9]{1,2})~/', '', $text);
    }

    /** Package entry ids of the 2nd, 3rd … segment of a line (the 1st keeps the line's own id). */
    public const SEGMENT_BASE = 1 << 40;

    public static function segmentId(int $lineId, int $segment): int
    {
        return $segment === 0 ? $lineId : self::SEGMENT_BASE + $lineId * 16 + $segment;
    }

    /** The translation line a package entry id belongs to. */
    public static function lineId(int $entryId): int
    {
        return $entryId >= self::SEGMENT_BASE ? intdiv($entryId - self::SEGMENT_BASE, 16) : $entryId;
    }

    /**
     * A subtitle line with timing codes (~sl:…~) is shown as several subtitles one after another: one pair per
     * segment when the English and Arabic have the same number, else the whole line as one.
     *
     * @return list<array{0: string, 1: string}> cleaned [source, target] pairs
     */
    public static function segments(string $source, string $target): array
    {
        $split = fn (string $t) => array_values(array_filter(preg_split('/~sl:[^~\s]*~/', $t), fn ($p) => trim(self::source($p)) !== ''));
        $src = $split($source);
        $tgt = $split($target);
        if (count($src) > 1 && count($src) === count($tgt) && count($src) <= 15) {
            return array_map(fn ($s, $t) => [self::source($s), self::target($t)], $src, $tgt);
        }

        return [[self::source($source), self::target($target)]];
    }

    public static function has(string $text): bool
    {
        return str_contains($text, '~') || preg_match('/<\/?[A-Za-z][^<>]{0,40}>/', $text) === 1;
    }
}
