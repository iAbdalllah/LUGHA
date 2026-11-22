//! Keeps installed packages in step with the account (same contract as the Mac and phone apps).

use lugha_core::crypto::DeviceKeys;
use lugha_core::{Package, TrustStore};
use sha2::{Digest, Sha256};

use crate::api::Api;
use crate::store::PackageStore;

#[derive(Debug, Default)]
pub struct Report {
    pub installed: Vec<String>,
    pub updated: Vec<String>,
    pub removed: Vec<String>,
    pub errors: Vec<String>,
}

pub fn now() -> i64 {
    std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).map(|d| d.as_secs() as i64).unwrap_or(0)
}

pub fn trust_from_json(raw: &str) -> Option<TrustStore> {
    let v: serde_json::Value = serde_json::from_str(raw).ok()?;
    let mut t = TrustStore::new();
    for k in v["keys"].as_array()? {
        t.add_base64(k["key_id"].as_str()?, k["public"].as_str()?).ok()?;
    }
    Some(t)
}

pub fn sync(api: &Api, store: &PackageStore, trust: &TrustStore, device: &DeviceKeys, device_id: &str, app_version: &str) -> Report {
    let mut r = Report::default();
    let current: std::collections::HashMap<String, String> = store.installed().into_iter().collect();
    match api.library() {
        Ok(items) => {
            for item in items {
                let Some(latest) = item.latest_version.clone() else { continue };
                if current.get(&item.package) == Some(&latest) {
                    continue;
                }
                match install(api, store, trust, device, device_id, &item.package, current.get(&item.package).map(String::as_str)) {
                    Ok(()) => if current.contains_key(&item.package) { r.updated.push(item.package) } else { r.installed.push(item.package) },
                    Err(e) => r.errors.push(format!("{}: {e}", item.package)),
                }
            }
        }
        Err(e) => r.errors.push(e.to_string()),
    }
    match api.heartbeat(device_id, app_version, &store.installed()) {
        Ok(revoked) => {
            for p in revoked {
                store.remove(&p);
                r.removed.push(p);
            }
        }
        Err(e) => r.errors.push(e.to_string()),
    }
    if let Ok(lics) = api.refresh_licenses(device_id) {
        for (p, l) in lics {
            let _ = Package::refresh_license(&store.dir_for(&p), &l, trust, device, now());
        }
    }
    r
}

fn install(api: &Api, store: &PackageStore, trust: &TrustStore, device: &DeviceKeys, device_id: &str, package: &str, from: Option<&str>) -> Result<(), String> {
    let mut grant = api.authorize_download(package, device_id, from).map_err(|e| e.to_string())?;
    let mut bytes = api.fetch(&grant.url).map_err(|e| e.to_string())?;
    if hex::encode(Sha256::digest(&bytes)) != grant.sha256 {
        return Err("checksum mismatch".into());
    }
    if let Err(e) = Package::install(&store.dir_for(package), &bytes, &grant.license_json, trust, device, now()) {
        if grant.kind != "delta" {
            return Err(e.to_string());
        }
        // Delta didn't apply (e.g. local files damaged): fall back to the full package.
        grant = api.authorize_download(package, device_id, None).map_err(|e| e.to_string())?;
        bytes = api.fetch(&grant.url).map_err(|e| e.to_string())?;
        Package::install(&store.dir_for(package), &bytes, &grant.license_json, trust, device, now()).map_err(|e| e.to_string())?;
    }
    api.complete_download(&grant.id, &grant.sha256).map_err(|e| e.to_string())
}

/// Open every installed package for matching.
pub fn load(store: &PackageStore, trust: &TrustStore, device: &DeviceKeys) -> (Vec<Package>, Vec<String>) {
    let mut ok = vec![];
    let mut errs = vec![];
    if let Ok(dirs) = std::fs::read_dir(&store.root) {
        for d in dirs.flatten().map(|d| d.path()).filter(|p| p.is_dir() && p.extension().is_none()) {
            match Package::open_dir(&d, trust, device, now()) {
                Ok(p) => ok.push(p),
                Err(e) => errs.push(format!("{}: {e}", d.display())),
            }
        }
    }
    (ok, errs)
}
