use lugha_core::builder::{build_delta, build_full, validate, BuildMeta, KeyFile};
use lugha_core::content::{self, EntryInput, GlossaryTerm};
use lugha_core::crypto::{self, DeviceKeys};
use lugha_core::license::{License, LicensePayload};
use lugha_core::matcher::Method;
use lugha_core::{Error, Matcher, Package, PackageProvider, ProviderChain, Query, TrustStore};

const NOW: i64 = 1_795_000_000;

fn entry(id: i64, src: &str, tgt: &str, cat: &str, scene: Option<&str>, seq: Option<i64>) -> EntryInput {
    EntryInput { id, source: src.into(), target: tgt.into(), category: cat.into(), speaker: None, scene: scene.map(Into::into), seq, aliases: vec![] }
}

fn entries() -> Vec<EntryInput> {
    vec![
        EntryInput { speaker: Some("Rim".into()), ..entry(1, "We leave at dawn. Nobody talks about tonight.", "نطلع مع الفجر. وأحد ما يتكلم عن الليلة.", "cutscenes", Some("M03"), Some(1)) },
        entry(2, "And if the Warden asks?", "وإذا سأل الـWarden؟", "cutscenes", Some("M03"), Some(2)),
        entry(3, "Then we tell him the caravan left without us.", "نقول له إن القافلة مشت بدوننا.", "cutscenes", Some("M03"), Some(3)),
        entry(4, "Catch up with the caravan before sunset", "الحق بالقافلة قبل الغروب", "missions", None, None),
        EntryInput { aliases: vec!["Continue Game".into()], ..entry(5, "Continue", "متابعة", "menus", None, None) },
        entry(6, "Water costs more than gold out here.", "الماء هنا أغلى من الذهب.", "side_dialogues", Some("bazaar"), Some(1)),
    ]
}

fn meta(version: &str) -> BuildMeta {
    serde_json::from_value(serde_json::json!({
        "game_id": "sandline", "package_id": "sandline-sa-full", "version": version,
        "localization_style": "saudi_white_dialect", "package_type": "full_localization",
        "categories": ["cutscenes", "missions", "menus", "side_dialogues"],
        "coverage_bp": {"cutscenes": 9700},
        "glossary": [{"term": "Warden", "dnt": true}]
    }))
    .unwrap()
}

struct World {
    key: KeyFile,
    trust: TrustStore,
    device: DeviceKeys,
}

impl World {
    fn new() -> Self {
        let key = KeyFile::generate("pk-test");
        let mut trust = TrustStore::new();
        trust.add_base64(&key.key_id, &key.public).unwrap();
        Self { key, trust, device: DeviceKeys::generate() }
    }

    fn license(&self, version: &str, content_key: &[u8; 32], device: &DeviceKeys, expires_at: i64) -> String {
        use base64::Engine;
        let payload = LicensePayload {
            v: 1, license_id: "lic1".into(), user: "u1".into(), device_fp: device.fingerprint(),
            package_id: "sandline-sa-full".into(), version: version.into(),
            content_key_wrapped: base64::engine::general_purpose::STANDARD.encode(crypto::wrap_key(content_key, &device.public)),
            issued_at: NOW, expires_at,
        };
        serde_json::to_string(&License::issue(&self.key.signing_key().unwrap(), &self.key.key_id, &payload)).unwrap()
    }
}

fn tmpdir(name: &str) -> std::path::PathBuf {
    let d = std::env::temp_dir().join(format!("lugha-test-{name}-{}", std::process::id()));
    let _ = std::fs::remove_dir_all(&d);
    d
}

#[test]
fn build_install_open_and_match() {
    let w = World::new();
    let ck: [u8; 32] = crypto::random_bytes();
    let (sealed, report) = build_full(&meta("1.0.0"), &entries(), &w.key, &ck, None, NOW).unwrap();
    assert!(report.ok());
    let dir = tmpdir("install");
    Package::install(&dir, &sealed.lpk, &w.license("1.0.0", &ck, &w.device, NOW + 86_400), &w.trust, &w.device, NOW).unwrap();

    let pkg = Package::open_dir(&dir, &w.trust, &w.device, NOW).unwrap();
    assert_eq!(pkg.entry_count().unwrap(), 6);
    let mut m = Matcher::new(vec![pkg]);

    let exact = m.lookup(&Query::new("WE LEAVE AT DAWN.   Nobody talks about tonight")).unwrap();
    assert_eq!((exact.method, exact.target.as_str()), (Method::Exact, "نطلع مع الفجر. وأحد ما يتكلم عن الليلة."));
    assert_eq!(exact.speaker.as_deref(), Some("Rim"));
    assert!(!exact.machine_generated);

    let alias = m.lookup(&Query::new("Continue Game")).unwrap();
    assert_eq!((alias.method, alias.entry_id), (Method::Alias, 5));

    // OCR confusions: 0/o, 1/l, rn/m
    let folded = m.lookup(&Query::new("Catch up with the caravan bef0re sunset")).unwrap();
    assert_eq!((folded.method, folded.entry_id), (Method::OcrFolded, 4));

    // Typos beyond folding → fuzzy
    let fuzzy = m.lookup(&Query::new("Water costs nore than gold out hers.")).unwrap();
    assert_eq!((fuzzy.method, fuzzy.entry_id), (Method::Fuzzy, 6));

    // Unknown text → nothing
    assert!(m.lookup(&Query::new("Press any key to start the adventure")).is_none());

    // Two OCR lines that are two separate entries
    let lines = m.lookup(&Query::new("Continue\nCatch up with the caravan before sunset")).unwrap();
    assert_eq!(lines.method, Method::Lines);
    assert_eq!(lines.target, "متابعة\nالحق بالقافلة قبل الغروب");
}

#[test]
fn scene_context_rescues_a_noisy_next_line() {
    let w = World::new();
    let ck: [u8; 32] = crypto::random_bytes();
    let (sealed, _) = build_full(&meta("1.0.0"), &entries(), &w.key, &ck, None, NOW).unwrap();
    let pkg = Package::from_lpk(&sealed.lpk, &License::parse(&w.license("1.0.0", &ck, &w.device, NOW + 60)).unwrap(), &w.trust, &w.device, NOW).unwrap();
    let mut m = Matcher::new(vec![pkg]);
    let noisy = "Thcn wc tcll hirn thc caravarn lcft withaut us.";

    // Without context the noisy line is rejected …
    assert!(m.lookup(&Query::new(noisy)).is_none());
    // … after line 2 of the same scene it is accepted as line 3.
    m.lookup(&Query::new("And if the Warden asks?")).unwrap();
    assert_eq!(m.lookup(&Query::new(noisy)).map(|h| h.entry_id), Some(3));
}

#[test]
fn tampering_wrong_device_expiry_and_wrong_version_are_rejected() {
    let w = World::new();
    let ck: [u8; 32] = crypto::random_bytes();
    let (sealed, _) = build_full(&meta("1.0.0"), &entries(), &w.key, &ck, None, NOW).unwrap();
    let ok = License::parse(&w.license("1.0.0", &ck, &w.device, NOW + 60)).unwrap();

    // Flip one byte in the encrypted payload (still a valid zip): file hash fails.
    let mut bad = sealed.lpk.clone();
    let pos = bad.windows(4).rposition(|w| w == b"PK\x01\x02").unwrap() - 10;
    bad[pos] ^= 0xFF;
    assert!(Package::from_lpk(&bad, &ok, &w.trust, &w.device, NOW).is_err());

    // Different signing key: signature fails.
    let mut other_trust = TrustStore::new();
    other_trust.add_base64("pk-test", &KeyFile::generate("pk-test").public).unwrap();
    assert!(matches!(Package::from_lpk(&sealed.lpk, &ok, &other_trust, &w.device, NOW), Err(Error::Signature(_))));

    // License for another device.
    let stranger = DeviceKeys::generate();
    assert!(matches!(Package::from_lpk(&sealed.lpk, &ok, &w.trust, &stranger, NOW), Err(Error::License(_))));

    // Expired offline window.
    assert!(matches!(Package::from_lpk(&sealed.lpk, &ok, &w.trust, &w.device, NOW + 61), Err(Error::License(_))));

    // License for another version.
    let v2 = License::parse(&w.license("2.0.0", &ck, &w.device, NOW + 60)).unwrap();
    assert!(matches!(Package::from_lpk(&sealed.lpk, &v2, &w.trust, &w.device, NOW), Err(Error::License(_))));
}

#[test]
fn delta_update_matches_full_build_and_rejects_wrong_base() {
    let w = World::new();
    let ck1: [u8; 32] = crypto::random_bytes();
    let ck2: [u8; 32] = crypto::random_bytes();
    let (v1, _) = build_full(&meta("1.0.0"), &entries(), &w.key, &ck1, None, NOW).unwrap();

    let mut next = entries();
    next[1].target = "وإذا سأل الآمر Warden؟".into(); // changed
    next.retain(|e| e.id != 6); // deleted
    next.push(entry(7, "Hold [Shift] to sprint", "اضغط [Shift] مطولًا للركض", "menus", None, None)); // added
    let mut m2 = meta("1.1.0");
    m2.glossary.push(GlossaryTerm { term: "caravan".into(), translation: Some("القافلة".into()), dnt: false });

    let old_db = {
        let p = Package::from_lpk(&v1.lpk, &License::parse(&w.license("1.0.0", &ck1, &w.device, NOW + 60)).unwrap(), &w.trust, &w.device, NOW).unwrap();
        content::to_bytes(p.connection()).unwrap()
    };
    let delta = build_delta(&old_db, "1.0.0", &m2, &next, &w.key, &ck2, NOW).unwrap();
    let (full_v2, _) = build_full(&m2, &next, &w.key, &ck2, None, NOW).unwrap();
    assert_eq!(delta.manifest.content_sha256, full_v2.manifest.content_sha256);
    assert!(delta.lpk.len() < full_v2.lpk.len());

    let dir = tmpdir("delta");
    Package::install(&dir, &v1.lpk, &w.license("1.0.0", &ck1, &w.device, NOW + 60), &w.trust, &w.device, NOW).unwrap();
    Package::install(&dir, &delta.lpk, &w.license("1.1.0", &ck2, &w.device, NOW + 60), &w.trust, &w.device, NOW).unwrap();

    let pkg = Package::open_dir(&dir, &w.trust, &w.device, NOW).unwrap();
    assert_eq!(pkg.manifest.version, "1.1.0");
    assert_eq!(pkg.debug_lookup_exact("Hold [Shift] to sprint").unwrap().as_deref(), Some("اضغط [Shift] مطولًا للركض"));
    assert_eq!(pkg.debug_lookup_exact("And if the Warden asks?").unwrap().as_deref(), Some("وإذا سأل الآمر Warden؟"));
    assert_eq!(pkg.debug_lookup_exact("Water costs more than gold out here.").unwrap(), None);

    // Applying the same delta again (base is now 1.1.0) is refused and leaves the install intact.
    assert!(Package::install(&dir, &delta.lpk, &w.license("1.1.0", &ck2, &w.device, NOW + 60), &w.trust, &w.device, NOW).is_err());
    assert_eq!(Package::open_dir(&dir, &w.trust, &w.device, NOW).unwrap().manifest.version, "1.1.0");
}

#[test]
fn minimized_packages_ship_no_source_text_but_still_match() {
    let w = World::new();
    let ck: [u8; 32] = crypto::random_bytes();
    let mut m = meta("1.0.0");
    m.minimized = true;
    let (sealed, _) = build_full(&m, &entries(), &w.key, &ck, None, NOW).unwrap();
    let pkg = Package::from_lpk(&sealed.lpk, &License::parse(&w.license("1.0.0", &ck, &w.device, NOW + 60)).unwrap(), &w.trust, &w.device, NOW).unwrap();
    let sources: i64 = pkg.connection().query_row("SELECT count(*) FROM entries WHERE source IS NOT NULL", [], |r| r.get(0)).unwrap();
    assert_eq!(sources, 0);

    let mut chain = ProviderChain::new(vec![Box::new(PackageProvider::new(Matcher::new(vec![pkg])))]);
    assert_eq!(chain.lookup(&Query::new("We leave at dawn. Nobody talks about tonight.")).map(|h| h.entry_id), Some(1));
    assert_eq!(chain.lookup(&Query::new("Water costs more than gold out here!!")).map(|h| h.entry_id), Some(6));
}

#[test]
fn validation_reports_problems() {
    let mut bad = entries();
    bad.push(entry(8, "Something", "  ", "menus", None, None));
    bad.push(entry(9, "Continue", "استمر", "menus", None, None)); // conflicts with entry 5
    bad.push(entry(10, "Bidi", "نص \u{202B}غير متوازن", "menus", None, None));
    bad.push(entry(11, "Codex entry", "مدخل", "codex", None, None));
    bad.push(entry(12, "Ask the Warden", "اسأل الحارس", "cutscenes", Some("M04"), Some(1)));
    let r = validate(&meta("1.0.0"), &bad);
    let codes: Vec<&str> = r.errors.iter().map(|i| i.code.as_str()).collect();
    for c in ["empty_target", "conflicting_duplicate", "unbalanced_bidi", "category_not_in_package"] {
        assert!(codes.contains(&c), "missing {c} in {codes:?}");
    }
    assert!(r.warnings.iter().any(|i| i.code == "glossary_dnt" && i.entry_id == Some(12)));
    assert!(matches!(build_full(&meta("1.0.0"), &bad, &KeyFile::generate("k"), &[0; 32], None, NOW), Err(Error::Build(_))));
}
