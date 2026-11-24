//! Pixel helpers on captured BGRA frames, plain Rust so they are tested on any OS: cropping a frame to a reading
//! area, and the readable text tone for Arabic drawn over a patch of the picture (same rule as the Mac app).

/// `rect` = (x, y, w, h) as fractions of the frame, origin top-left. Returns (width, height, bgra).
pub fn crop(width: u32, height: u32, bgra: &[u8], rect: (f32, f32, f32, f32)) -> Option<(u32, u32, Vec<u8>)> {
    let (fx, fy, fw, fh) = rect;
    let x0 = ((fx.clamp(0.0, 1.0)) * width as f32) as u32;
    let y0 = ((fy.clamp(0.0, 1.0)) * height as f32) as u32;
    let x1 = (((fx + fw).clamp(0.0, 1.0)) * width as f32) as u32;
    let y1 = (((fy + fh).clamp(0.0, 1.0)) * height as f32) as u32;
    if x1 <= x0 + 8 || y1 <= y0 + 8 || bgra.len() < (width * height * 4) as usize {
        return None;
    }
    let (w, h) = (x1 - x0, y1 - y0);
    let mut out = Vec::with_capacity((w * h * 4) as usize);
    for y in y0..y1 {
        let start = ((y * width + x0) * 4) as usize;
        out.extend_from_slice(&bgra[start..start + (w * 4) as usize]);
    }
    Some((w, h, out))
}

/// How to draw Arabic over a patch so it reads: text colour (#RRGGBB) and backdrop strength (0–0.6) in the opposite
/// colour. Bright patches get dark text; busy or mid-grey ones (gravel, foliage) get a backdrop.
pub fn tone_for(luma: f32, spread: f32) -> (String, f32) {
    let dark_text = luma > 0.62;
    let contrast = if dark_text { luma - 0.07 } else { 1.0 - luma };
    let shade = ((0.62 - contrast) * 1.1 + (spread - 0.06) * 2.6).clamp(0.0, 0.6);
    ((if dark_text { "#121217" } else { "#FFFFFF" }).into(), (shade * 100.0).round() / 100.0)
}

/// The tone of a patch of the frame (fractions, origin top-left).
pub fn tone(width: u32, height: u32, bgra: &[u8], rect: (f32, f32, f32, f32)) -> (String, f32) {
    let Some((w, h, px)) = crop(width, height, bgra, rect) else { return tone_for(0.2, 0.1) };
    let mut l = Vec::new();
    let (sx, sy) = ((w / 60).max(1), (h / 12).max(1));
    for y in (0..h).step_by(sy as usize) {
        for x in (0..w).step_by(sx as usize) {
            let i = ((y * w + x) * 4) as usize;
            let (b, g, r) = (px[i] as f32 / 255.0, px[i + 1] as f32 / 255.0, px[i + 2] as f32 / 255.0);
            l.push(0.2126 * r + 0.7152 * g + 0.0722 * b);
        }
    }
    let n = l.len().max(1) as f32;
    let mean = l.iter().sum::<f32>() / n;
    let spread = (l.iter().map(|v| (v - mean) * (v - mean)).sum::<f32>() / n).sqrt();
    tone_for(mean, spread)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn crops_the_area_and_rejects_slivers() {
        let (w, h) = (100u32, 50u32);
        let px: Vec<u8> = (0..w * h).flat_map(|i| [(i % 256) as u8, 0, 0, 255]).collect();
        let (cw, ch, c) = crop(w, h, &px, (0.5, 0.5, 0.5, 0.5)).unwrap();
        assert_eq!((cw, ch), (50, 25));
        assert_eq!(c[0], ((25 * 100 + 50) % 256) as u8);
        assert!(crop(w, h, &px, (0.0, 0.0, 0.01, 0.5)).is_none());
    }

    #[test]
    fn tone_adapts_to_the_picture() {
        let gravel = tone_for(0.45, 0.15);
        assert_eq!(gravel.0, "#FFFFFF");
        assert!(gravel.1 > 0.25);
        assert_eq!(tone_for(0.85, 0.04), ("#121217".into(), 0.0));
        assert_eq!(tone_for(0.08, 0.03).1, 0.0);
    }
}
