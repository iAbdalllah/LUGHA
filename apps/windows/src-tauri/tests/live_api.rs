//! End-to-end against a running backend: `LUGHA_API=http://127.0.0.1:8040 cargo test --no-default-features --test live_api`.
//! Skipped when LUGHA_API is unset. Uses the seeded test player and removes the device it registers.

use lugha_core::crypto::DeviceKeys;
use lugha_core::{Matcher, Query};
use lugha_windows::api::Api;
use lugha_windows::store::PackageStore;
use lugha_windows::sync;

#[test]
fn sign_in_sync_and_translate() {
    let Ok(base) = std::env::var("LUGHA_API") else {
        eprintln!("LUGHA_API not set — skipped");
        return;
    };
    let mut api = Api::new(&base, None);
    let (token, name) = api.login("player@lugha.test", "password", "Windows test").expect("login");
    assert!(!name.is_empty());
    api.token = Some(token);

    let trust = sync::trust_from_json(&api.trust_json().unwrap()).expect("trust");
    let device = DeviceKeys::generate();
    let device_id = api.register_device("Windows test PC", &lugha_link::b64(&device.public), "0.1.0").expect("register");

    let root = std::env::temp_dir().join(format!("lugha-win-live-{}", std::process::id()));
    let store = PackageStore::new(Some(root.clone())).unwrap();
    let report = sync::sync(&api, &store, &trust, &device, &device_id, "0.1.0");
    eprintln!("{report:?}");
    let ok = report.errors.is_empty() && !store.installed().is_empty();

    let (pkgs, errs) = sync::load(&store, &trust, &device);
    let mut m = Matcher::new(pkgs);
    let hit = m.lookup(&Query::new("Water costs more than gold out here"));

    // Clean up before asserting.
    let _ = api_delete_device(&api, &device_id);
    let _ = std::fs::remove_dir_all(root);

    assert!(ok, "sync failed: {report:?}");
    assert!(errs.is_empty(), "{errs:?}");
    let hit = hit.expect("seeded line should match");
    assert_eq!(hit.target, "الماء هنا أغلى من الذهب.");
    eprintln!("→ {}", hit.target);
}

fn api_delete_device(api: &Api, id: &str) -> Result<(), ureq::Error> {
    ureq::delete(&format!("{}/api/v1/devices/{id}", api.base))
        .set("Accept", "application/json")
        .set("Authorization", &format!("Bearer {}", api.token.as_deref().unwrap_or_default()))
        .call()
        .map(|_| ())
}
