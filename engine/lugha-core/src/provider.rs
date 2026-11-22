//! The Phase 6 seam (docs/01 §4): a chain of translation providers. v1 ships exactly one — packages.

use crate::matcher::{Hit, Matcher, Query};

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ProviderKind {
    Package,
    /// Reserved for optional future providers; their hits must carry `machine_generated = true`.
    Machine,
}

pub trait TranslationProvider: Send {
    fn id(&self) -> &'static str;
    fn kind(&self) -> ProviderKind;
    fn lookup(&mut self, q: &Query) -> Option<Hit>;
}

pub struct PackageProvider {
    matcher: Matcher,
}

impl PackageProvider {
    pub fn new(matcher: Matcher) -> Self {
        Self { matcher }
    }

    pub fn matcher(&mut self) -> &mut Matcher {
        &mut self.matcher
    }
}

impl TranslationProvider for PackageProvider {
    fn id(&self) -> &'static str {
        "package"
    }

    fn kind(&self) -> ProviderKind {
        ProviderKind::Package
    }

    fn lookup(&mut self, q: &Query) -> Option<Hit> {
        self.matcher.lookup(q)
    }
}

#[derive(Default)]
pub struct ProviderChain {
    providers: Vec<Box<dyn TranslationProvider>>,
}

impl ProviderChain {
    pub fn new(providers: Vec<Box<dyn TranslationProvider>>) -> Self {
        Self { providers }
    }

    /// First provider with an answer wins. Package providers always come first.
    pub fn lookup(&mut self, q: &Query) -> Option<Hit> {
        self.providers.iter_mut().find_map(|p| p.lookup(q))
    }
}
