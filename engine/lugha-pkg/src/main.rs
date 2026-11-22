//! lugha-pkg — build, validate, sign, diff and inspect LUGHA packages (docs/04-package-spec.md §8).
//!
//! Secrets never go on the command line: content keys come from LUGHA_CONTENT_KEY / LUGHA_BASE_CONTENT_KEY
//! (64 hex chars) and the signing key from a key file. Output is JSON on stdout; errors go to stderr with a
//! non-zero exit code (2 = validation failed, 1 = anything else).

use std::path::PathBuf;
use std::process::ExitCode;
use std::time::{Instant, SystemTime, UNIX_EPOCH};

use clap::{Parser, Subcommand};
use lugha_core::builder::{self, BuildMeta, KeyFile};
use lugha_core::content::{self, EntryInput};
use lugha_core::package::Container;
use lugha_core::{Matcher, Package, Query, TrustStore};
use serde_json::json;

#[derive(Parser)]
#[command(name = "lugha-pkg", version, about = "LUGHA localization package tool")]
struct Cli {
    #[command(subcommand)]
    cmd: Cmd,
}

#[derive(Subcommand)]
enum Cmd {
    /// Generate an Ed25519 signing key file (written with 0600 permissions).
    Keygen {
        #[arg(long)]
        key_id: String,
        #[arg(long)]
        out: PathBuf,
    },
    /// Validate an export without building (exit 2 on errors).
    Validate {
        #[arg(long)]
        meta: PathBuf,
        #[arg(long)]
        entries: PathBuf,
    },
    /// Build and sign a full package. Content key: $LUGHA_CONTENT_KEY.
    Build {
        #[arg(long)]
        meta: PathBuf,
        #[arg(long)]
        entries: PathBuf,
        #[arg(long)]
        key: PathBuf,
        #[arg(long)]
        profile: Option<PathBuf>,
        #[arg(long)]
        out: PathBuf,
    },
    /// Build a signed delta from the previous full package. Keys: $LUGHA_BASE_CONTENT_KEY, $LUGHA_CONTENT_KEY.
    Delta {
        #[arg(long)]
        base: PathBuf,
        #[arg(long)]
        meta: PathBuf,
        #[arg(long)]
        entries: PathBuf,
        #[arg(long)]
        key: PathBuf,
        #[arg(long)]
        out: PathBuf,
    },
    /// Print a package's manifest; with --key, also verify its signature and file hashes.
    Inspect {
        file: PathBuf,
        #[arg(long)]
        key: Option<PathBuf>,
    },
    /// Look a line up in a package (development aid). Content key: $LUGHA_CONTENT_KEY.
    Lookup {
        #[arg(long)]
        lpk: PathBuf,
        #[arg(long)]
        key: PathBuf,
        text: Vec<String>,
    },
    /// Generate a device key pair (development/testing; apps generate theirs on-device).
    DeviceKeygen,
    /// Open a package the way an app does — with a license and device secret ($LUGHA_DEVICE_SECRET, hex) — and
    /// optionally look a line up. Verifies signature, file hashes, license binding/expiry and content hash.
    Open {
        #[arg(long)]
        lpk: PathBuf,
        /// Trust: a key file (only its public key is used)
        #[arg(long)]
        key: PathBuf,
        #[arg(long)]
        license: PathBuf,
        text: Vec<String>,
    },
    /// Benchmark matching on a synthetic package of N lines.
    Bench {
        #[arg(long, default_value_t = 50_000)]
        lines: i64,
    },
}

fn now() -> i64 {
    SystemTime::now().duration_since(UNIX_EPOCH).unwrap().as_secs() as i64
}

fn read_json<T: serde::de::DeserializeOwned>(p: &PathBuf) -> Result<T, String> {
    serde_json::from_slice(&std::fs::read(p).map_err(|e| format!("{}: {e}", p.display()))?).map_err(|e| format!("{}: {e}", p.display()))
}

/// JSON Lines (one entry per line) or a JSON array.
fn read_entries(p: &PathBuf) -> Result<Vec<EntryInput>, String> {
    let raw = std::fs::read_to_string(p).map_err(|e| format!("{}: {e}", p.display()))?;
    if raw.trim_start().starts_with('[') {
        return serde_json::from_str(&raw).map_err(|e| e.to_string());
    }
    raw.lines()
        .enumerate()
        .filter(|(_, l)| !l.trim().is_empty())
        .map(|(i, l)| serde_json::from_str(l).map_err(|e| format!("{} line {}: {e}", p.display(), i + 1)))
        .collect()
}

fn env_key(name: &str) -> Result<[u8; 32], String> {
    let v = std::env::var(name).map_err(|_| format!("${name} is not set"))?;
    hex::decode(v.trim()).ok().and_then(|b| b.try_into().ok()).ok_or_else(|| format!("${name} must be 64 hex characters"))
}

fn trust_from(key: &KeyFile) -> Result<TrustStore, String> {
    let mut t = TrustStore::new();
    t.add_base64(&key.key_id, &key.public).map_err(|e| e.to_string())?;
    Ok(t)
}

fn write_out(path: &PathBuf, bytes: &[u8]) -> Result<serde_json::Value, String> {
    use sha2_hex::sha256_hex;
    std::fs::write(path, bytes).map_err(|e| format!("{}: {e}", path.display()))?;
    Ok(json!({"path": path, "size": bytes.len(), "sha256": sha256_hex(bytes)}))
}

mod sha2_hex {
    pub fn sha256_hex(b: &[u8]) -> String {
        hex::encode(lugha_core::crypto::sha256_bytes(b))
    }
}

fn run(cli: Cli) -> Result<(serde_json::Value, u8), String> {
    match cli.cmd {
        Cmd::Keygen { key_id, out } => {
            let kf = KeyFile::generate(&key_id);
            std::fs::write(&out, serde_json::to_vec_pretty(&kf).unwrap()).map_err(|e| e.to_string())?;
            #[cfg(unix)]
            {
                use std::os::unix::fs::PermissionsExt;
                std::fs::set_permissions(&out, std::fs::Permissions::from_mode(0o600)).map_err(|e| e.to_string())?;
            }
            Ok((json!({"key_id": kf.key_id, "public": kf.public, "path": out}), 0))
        }
        Cmd::Validate { meta, entries } => {
            let report = builder::validate(&read_json::<BuildMeta>(&meta)?, &read_entries(&entries)?);
            let code = if report.ok() { 0 } else { 2 };
            Ok((json!({"report": report}), code))
        }
        Cmd::Build { meta, entries, key, profile, out } => {
            let meta: BuildMeta = read_json(&meta)?;
            let entries = read_entries(&entries)?;
            let report = builder::validate(&meta, &entries);
            if !report.ok() {
                return Ok((json!({"report": report}), 2));
            }
            let kf: KeyFile = read_json(&key)?;
            let profile = profile.map(|p| std::fs::read(&p).map_err(|e| e.to_string())).transpose()?;
            let (sealed, report) = builder::build_full(&meta, &entries, &kf, &env_key("LUGHA_CONTENT_KEY")?, profile.as_deref(), now()).map_err(|e| e.to_string())?;
            Ok((json!({"file": write_out(&out, &sealed.lpk)?, "manifest": sealed.manifest, "report": report}), 0))
        }
        Cmd::Delta { base, meta, entries, key, out } => {
            let kf: KeyFile = read_json(&key)?;
            let trust = trust_from(&kf)?;
            let base_bytes = std::fs::read(&base).map_err(|e| e.to_string())?;
            let base_pkg = Package::from_lpk_with_key(&base_bytes, &env_key("LUGHA_BASE_CONTENT_KEY")?, &trust).map_err(|e| e.to_string())?;
            let meta: BuildMeta = read_json(&meta)?;
            let entries = read_entries(&entries)?;
            let report = builder::validate(&meta, &entries);
            if !report.ok() {
                return Ok((json!({"report": report}), 2));
            }
            let old_db = content::to_bytes(base_pkg.connection()).map_err(|e| e.to_string())?;
            let sealed = builder::build_delta(&old_db, &base_pkg.manifest.version, &meta, &entries, &kf, &env_key("LUGHA_CONTENT_KEY")?, now()).map_err(|e| e.to_string())?;
            Ok((json!({"file": write_out(&out, &sealed.lpk)?, "manifest": sealed.manifest, "report": report}), 0))
        }
        Cmd::Inspect { file, key } => {
            let c = Container::read(&std::fs::read(&file).map_err(|e| e.to_string())?).map_err(|e| e.to_string())?;
            let verified = match key {
                Some(k) => {
                    c.verify(&trust_from(&read_json(&k)?)?).map_err(|e| e.to_string())?;
                    true
                }
                None => false,
            };
            Ok((json!({"manifest": c.manifest, "verified": verified}), 0))
        }
        Cmd::Lookup { lpk, key, text } => {
            let kf: KeyFile = read_json(&key)?;
            let pkg = Package::from_lpk_with_key(&std::fs::read(&lpk).map_err(|e| e.to_string())?, &env_key("LUGHA_CONTENT_KEY")?, &trust_from(&kf)?).map_err(|e| e.to_string())?;
            let mut m = Matcher::new(vec![pkg]);
            let started = Instant::now();
            let hit = m.lookup(&Query::new(text.join(" ")));
            Ok((json!({"hit": hit, "micros": started.elapsed().as_micros()}), 0))
        }
        Cmd::DeviceKeygen => {
            use base64::Engine;
            let d = lugha_core::crypto::DeviceKeys::generate();
            Ok((json!({"secret_hex": hex::encode(d.secret), "public": base64::engine::general_purpose::STANDARD.encode(d.public), "fingerprint": d.fingerprint()}), 0))
        }
        Cmd::Open { lpk, key, license, text } => {
            let kf: KeyFile = read_json(&key)?;
            let device = lugha_core::crypto::DeviceKeys::from_secret(env_key("LUGHA_DEVICE_SECRET")?);
            let lic = lugha_core::License::parse(&std::fs::read_to_string(&license).map_err(|e| e.to_string())?).map_err(|e| e.to_string())?;
            let pkg = Package::from_lpk(&std::fs::read(&lpk).map_err(|e| e.to_string())?, &lic, &trust_from(&kf)?, &device, now()).map_err(|e| e.to_string())?;
            let manifest = pkg.manifest.clone();
            let count = pkg.entry_count().map_err(|e| e.to_string())?;
            let hit = if text.is_empty() { None } else { Matcher::new(vec![pkg]).lookup(&Query::new(text.join(" "))) };
            Ok((json!({"opened": true, "package_id": manifest.package_id, "version": manifest.version, "entries": count, "hit": hit}), 0))
        }
        Cmd::Bench { lines } => bench(lines).map(|v| (v, 0)),
    }
}

/// Synthetic workload: realistic-length lines from a word pool; measures build time, package size and
/// lookup latency for exact hits, OCR-noisy hits and misses.
fn bench(n: i64) -> Result<serde_json::Value, String> {
    use rand::{Rng, SeedableRng};
    let words = ["the", "caravan", "leaves", "at", "dawn", "nobody", "talks", "about", "tonight", "warden", "water", "gold", "desert",
        "storm", "follow", "me", "quickly", "before", "sunset", "we", "need", "more", "supplies", "where", "is", "the", "oasis", "hold", "fire", "move"];
    let mut rng = rand::rngs::StdRng::seed_from_u64(7);
    let entries: Vec<EntryInput> = (1..=n)
        .map(|id| {
            let len = rng.gen_range(4..14);
            let src = (0..len).map(|_| words[rng.gen_range(0..words.len())]).collect::<Vec<_>>().join(" ") + &format!(" #{id}");
            EntryInput { id, source: src, target: format!("سطر مترجم رقم {id}"), category: "dialogues".into(), speaker: None, scene: Some(format!("s{}", id / 20)), seq: Some(id % 20), aliases: vec![] }
        })
        .collect();
    let meta: BuildMeta = serde_json::from_value(json!({"game_id": "bench", "package_id": "bench-full", "version": "1.0.0",
        "localization_style": "msa", "package_type": "bench", "categories": ["dialogues"]})).unwrap();
    let kf = KeyFile::generate("bench");
    let ck: [u8; 32] = lugha_core::crypto::random_bytes();

    let t = Instant::now();
    let (sealed, _) = builder::build_full(&meta, &entries, &kf, &ck, None, now()).map_err(|e| e.to_string())?;
    let build_ms = t.elapsed().as_millis();
    let t = Instant::now();
    let pkg = Package::from_lpk_with_key(&sealed.lpk, &ck, &trust_from(&kf)?).map_err(|e| e.to_string())?;
    let open_ms = t.elapsed().as_millis();
    let mut m = Matcher::new(vec![pkg]);

    // (query, expected entry id or None)
    let mut measure = |queries: Vec<(String, Option<i64>)>| {
        let mut correct = 0;
        let mut us: Vec<u128> = queries.iter().map(|(q, want)| {
            m.reset_context();
            let t = Instant::now();
            let got = m.lookup(&Query::new(q.clone())).map(|h| h.entry_id);
            let el = t.elapsed().as_micros();
            if got == *want {
                correct += 1;
            }
            el
        }).collect();
        us.sort_unstable();
        json!({"p50_us": us[us.len() / 2], "p95_us": us[us.len() * 95 / 100], "max_us": us[us.len() - 1],
            "correct_pct": (correct as f64 * 1000.0 / queries.len() as f64).round() / 10.0})
    };
    let sample: Vec<&EntryInput> = (0..500).map(|i| &entries[(i * 97) % entries.len()]).collect();
    let exact = measure(sample.iter().map(|e| (e.source.to_uppercase(), Some(e.id))).collect());
    let noisy = measure(sample.iter().map(|e| (e.source.replacen('e', "c", 2).replacen('a', "o", 1), Some(e.id))).collect());
    let misses = measure((0..500).map(|i| (format!("completely unrelated hud text {i} press start"), None)).collect());

    Ok(json!({"lines": n, "lpk_bytes": sealed.lpk.len(), "build_ms": build_ms, "open_ms": open_ms,
        "exact": exact, "ocr_noisy": noisy, "miss": misses}))
}

fn main() -> ExitCode {
    match run(Cli::parse()) {
        Ok((out, code)) => {
            println!("{}", serde_json::to_string_pretty(&out).unwrap());
            ExitCode::from(code)
        }
        Err(e) => {
            eprintln!("lugha-pkg: {e}");
            ExitCode::from(1)
        }
    }
}
