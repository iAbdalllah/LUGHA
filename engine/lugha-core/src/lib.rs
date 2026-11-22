//! LUGHA package engine (docs/04-package-spec.md).
//!
//! Client apps use: [`Package::open`] (verify + decrypt an installed `.lpk` with its license),
//! [`Matcher`] (OCR text → reviewed Arabic line), [`delta::apply`] (incremental updates) and the
//! [`provider`] chain. Building/signing/issuing lives behind the `issuer` feature (lugha-pkg, tests).

pub mod content;
pub mod crypto;
pub mod delta;
pub mod error;
pub mod license;
pub mod manifest;
pub mod matcher;
pub mod normalize;
pub mod package;
pub mod provider;
pub mod trust;

#[cfg(feature = "issuer")]
pub mod builder;

pub use error::{Error, Result};
pub use license::License;
pub use manifest::Manifest;
pub use matcher::{Hit, Matcher, Query};
pub use package::Package;
pub use provider::{PackageProvider, ProviderChain, TranslationProvider};
pub use trust::TrustStore;

/// Package format this engine reads.
pub const FORMAT: &str = "lpk/1";
/// Version of this engine, compared against a manifest's `min_app_version` by the host app.
pub const ENGINE_VERSION: &str = env!("CARGO_PKG_VERSION");
