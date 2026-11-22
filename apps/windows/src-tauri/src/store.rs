//! Installed packages in %APPDATA%\LUGHA\packages\<package_id>\ (engine layout).

use std::path::{Path, PathBuf};

pub struct PackageStore {
    pub root: PathBuf,
}

impl PackageStore {
    pub fn new(root: Option<PathBuf>) -> std::io::Result<Self> {
        let root = root.unwrap_or_else(|| dirs::data_dir().unwrap_or_else(std::env::temp_dir).join("LUGHA").join("packages"));
        std::fs::create_dir_all(&root)?;
        Ok(Self { root })
    }

    pub fn dir_for(&self, package_id: &str) -> PathBuf {
        self.root.join(package_id)
    }

    /// (package_id, installed version) from each manifest.
    pub fn installed(&self) -> Vec<(String, String)> {
        let Ok(dirs) = std::fs::read_dir(&self.root) else { return vec![] };
        let mut out: Vec<(String, String)> = dirs
            .flatten()
            .map(|d| d.path())
            .filter(|p| p.is_dir() && p.extension().is_none())
            .filter_map(|p| read_manifest(&p))
            .collect();
        out.sort();
        out
    }

    pub fn remove(&self, package_id: &str) {
        let _ = std::fs::remove_dir_all(self.dir_for(package_id));
    }
}

fn read_manifest(dir: &Path) -> Option<(String, String)> {
    let v: serde_json::Value = serde_json::from_slice(&std::fs::read(dir.join("manifest.json")).ok()?).ok()?;
    Some((v["package_id"].as_str()?.to_string(), v["version"].as_str()?.to_string()))
}
