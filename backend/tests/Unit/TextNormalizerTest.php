<?php

namespace Tests\Unit;

use App\Domain\Localization\TextNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Shared fixtures: lugha-core (Rust) must produce identical output for these. */
class TextNormalizerTest extends TestCase
{
    public static function cases(): array
    {
        return [
            ['We leave at dawn.', 'we leave at dawn'],
            ["  We   leave\nat dawn!!  ", 'we leave at dawn'],
            ['“Quoted” — text…', '"quoted" - text'],
            ['It’s FINE?', "it's fine"],
            ['ﬁnal', 'final'],                 // NFKC ligature
        ];
    }

    #[DataProvider('cases')]
    public function test_normalizes(string $in, string $out): void
    {
        $this->assertSame($out, TextNormalizer::normalize($in));
    }

    public function test_hash_is_stable_across_cosmetic_differences(): void
    {
        $this->assertSame(TextNormalizer::hash('Nobody talks about tonight.'), TextNormalizer::hash('nobody talks  about tonight'));
        $this->assertSame(64, strlen(TextNormalizer::hash('x')));
    }
}
