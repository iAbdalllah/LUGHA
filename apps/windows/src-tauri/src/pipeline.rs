//! OCR lines in, subtitle out — the same state machine as the Mac app (stable-frame gating, hide after silence).

use std::time::{Duration, Instant};

#[derive(Debug, Clone, PartialEq)]
pub struct Subtitle {
    pub text: String,
    pub speaker: Option<String>,
    pub source: String,
}

pub struct Pipeline {
    pub stable_frames: u32,
    pub hide_after: Duration,
    pub current: Option<Subtitle>,
    pub history: Vec<Subtitle>,
    candidate: Option<String>,
    count: u32,
    last_text: Option<Instant>,
    shown_source: Option<String>,
}

impl Default for Pipeline {
    fn default() -> Self {
        Self { stable_frames: 2, hide_after: Duration::from_millis(1200), current: None, history: vec![], candidate: None, count: 0, last_text: None, shown_source: None }
    }
}

impl Pipeline {
    /// Feed one frame's OCR lines; `lookup` maps text → (target, speaker). Returns true when `current` changed.
    pub fn ingest(&mut self, lines: &[String], now: Instant, mut lookup: impl FnMut(&str) -> Option<(String, Option<String>)>) -> bool {
        let text = lines.iter().map(|l| l.trim()).filter(|l| !l.is_empty()).collect::<Vec<_>>().join("\n");
        if text.is_empty() {
            self.candidate = None;
            self.count = 0;
            if self.current.is_some() && self.last_text.is_some_and(|t| now.duration_since(t) >= self.hide_after) {
                self.current = None;
                self.shown_source = None;
                return true;
            }
            return false;
        }
        self.last_text = Some(now);
        if Some(&text) == self.shown_source.as_ref() {
            return false;
        }
        if Some(&text) == self.candidate.as_ref() {
            self.count += 1;
        } else {
            self.candidate = Some(text.clone());
            self.count = 1;
        }
        if self.count < self.stable_frames {
            return false;
        }
        self.count = 0;
        self.shown_source = Some(text.clone());
        match lookup(&text) {
            None => {
                let changed = self.current.is_some();
                self.current = None;
                changed
            }
            Some((target, speaker)) => {
                let sub = Subtitle { text: target, speaker, source: text };
                if self.current.as_ref() == Some(&sub) {
                    return false;
                }
                self.history.insert(0, sub.clone());
                self.history.truncate(50);
                self.current = Some(sub);
                true
            }
        }
    }
}
