//! Canonical source-text normalization. MUST stay byte-identical with the backend's
//! `App\Domain\Localization\TextNormalizer` — both are tested against `engine/fixtures/normalize.json`.

use sha2::{Digest, Sha256};
use unicode_normalization::UnicodeNormalization;

/// Whitespace as PHP PCRE `\s` (without UCP) sees it after NFKC.
fn is_pcre_space(c: char) -> bool {
    matches!(c, ' ' | '\t' | '\n' | '\u{0B}' | '\u{0C}' | '\r')
}

pub fn normalize(text: &str) -> String {
    let lowered: String = text.nfkc().collect::<String>().to_lowercase();

    let mut replaced = String::with_capacity(lowered.len());
    for c in lowered.chars() {
        match c {
            '\u{2018}' | '\u{2019}' => replaced.push('\''),
            '\u{201C}' | '\u{201D}' => replaced.push('"'),
            '\u{2013}' | '\u{2014}' => replaced.push('-'),
            '\u{2026}' => replaced.push_str("..."),
            _ => replaced.push(c),
        }
    }

    // Collapse whitespace runs to one space.
    let mut collapsed = String::with_capacity(replaced.len());
    let mut in_space = false;
    for c in replaced.chars() {
        if is_pcre_space(c) {
            if !in_space {
                collapsed.push(' ');
            }
            in_space = true;
        } else {
            collapsed.push(c);
            in_space = false;
        }
    }

    // PHP trim() default set (after collapsing only ' ' and NUL can remain at the edges), then rtrim punctuation.
    collapsed
        .trim_matches(|c| c == ' ' || c == '\0')
        .trim_end_matches([' ', '.', ',', '!', '?', ';', ':', '"', '\''])
        .to_string()
}

/// OCR confusion folding (spec §5 step 4), applied to already-normalized text on both sides.
pub fn ocr_fold(normalized: &str) -> String {
    let s = normalized.replace("rn", "m").replace("vv", "w");
    s.chars()
        .map(|c| match c {
            '0' => 'o',
            '1' | 'i' | '|' | '!' => 'l',
            '5' => 's',
            '8' => 'b',
            _ => c,
        })
        .collect()
}

pub fn sha256(text: &str) -> [u8; 32] {
    Sha256::digest(text.as_bytes()).into()
}

/// The package lookup key: sha256(normalize(text)).
pub fn key_hash(text: &str) -> [u8; 32] {
    sha256(&normalize(text))
}

/// 32-bit FNV-1a hashes of character trigrams of normalized text (deduplicated, sorted).
pub fn trigrams(normalized: &str) -> Vec<u32> {
    let chars: Vec<char> = format!("  {normalized} ").chars().collect();
    let mut out: Vec<u32> = chars
        .windows(3)
        .map(|w| {
            let mut h: u32 = 0x811c9dc5;
            for c in w {
                let mut buf = [0u8; 4];
                for b in c.encode_utf8(&mut buf).bytes() {
                    h ^= b as u32;
                    h = h.wrapping_mul(0x01000193);
                }
            }
            h
        })
        .collect();
    out.sort_unstable();
    out.dedup();
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn matches_shared_fixtures() {
        let raw = include_str!("../../fixtures/normalize.json");
        let cases: Vec<(String, String)> = serde_json::from_str(raw).unwrap();
        for (input, expected) in cases {
            assert_eq!(normalize(&input), expected, "input: {input:?}");
        }
    }

    #[test]
    fn ocr_fold_collapses_common_confusions() {
        assert_eq!(ocr_fold(&normalize("Modern 0ffice")), ocr_fold(&normalize("Modem office")));
        assert_eq!(ocr_fold("1ll|"), "llll");
    }

    #[test]
    fn trigrams_overlap_for_similar_text() {
        let a = trigrams(&normalize("We leave at dawn"));
        let b = trigrams(&normalize("We leave at dawm"));
        let shared = a.iter().filter(|g| b.contains(g)).count();
        assert!(shared as f64 / a.len() as f64 > 0.7);
    }
}
