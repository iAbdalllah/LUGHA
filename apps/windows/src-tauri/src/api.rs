//! LUGHA REST API v1 (docs/03-api.md) — what the desktop app needs. Blocking (ureq); call off the UI thread.

use serde::Deserialize;
use serde_json::{json, Value};

#[derive(Debug, thiserror::Error)]
pub enum ApiError {
    #[error("{detail}")]
    Http { status: u16, code: String, detail: String },
    #[error("network: {0}")]
    Network(String),
}

#[derive(Debug, Clone, Deserialize, PartialEq)]
pub struct LibraryItem {
    pub package: String,
    pub name: String,
    pub latest_version: Option<String>,
}

#[derive(Debug, Clone)]
pub struct DownloadGrant {
    pub id: String,
    pub url: String,
    pub kind: String,
    pub version: String,
    pub sha256: String,
    pub license_json: String,
}

#[derive(Debug, Clone, Deserialize)]
pub struct DeviceCodeStart {
    pub device_code: String,
    pub user_code: String,
    pub verification_url_complete: String,
    pub expires_in: u64,
    pub interval: u64,
}

pub struct Api {
    pub base: String,
    pub token: Option<String>,
    pub language: String,
    agent: ureq::Agent,
}

impl Api {
    pub fn new(base: &str, token: Option<String>) -> Self {
        Self { base: base.trim_end_matches('/').to_string(), token, language: "ar".into(), agent: ureq::AgentBuilder::new().timeout(std::time::Duration::from_secs(30)).build() }
    }

    fn call(&self, method: &str, path: &str, body: Option<Value>) -> Result<Value, ApiError> {
        let mut req = self.agent.request(method, &format!("{}/api/v1/{path}", self.base)).set("Accept", "application/json").set("Accept-Language", &self.language);
        if let Some(t) = &self.token {
            req = req.set("Authorization", &format!("Bearer {t}"));
        }
        let res = match body {
            Some(b) => req.send_json(b),
            None => req.call(),
        };
        match res {
            Ok(r) => {
                let text = r.into_string().map_err(|e| ApiError::Network(e.to_string()))?;
                Ok(if text.is_empty() { Value::Null } else { serde_json::from_str(&text).unwrap_or(Value::Null) })
            }
            Err(ureq::Error::Status(status, r)) => {
                let v: Value = r.into_json().unwrap_or(Value::Null);
                Err(ApiError::Http {
                    status,
                    code: v["code"].as_str().unwrap_or("http_error").to_string(),
                    detail: v["detail"].as_str().or(v["title"].as_str()).unwrap_or("request failed").to_string(),
                })
            }
            Err(e) => Err(ApiError::Network(e.to_string())),
        }
    }

    pub fn trust_json(&self) -> Result<String, ApiError> {
        Ok(self.call("GET", "trust", None)?.to_string())
    }

    pub fn start_device_code(&self, client_name: &str) -> Result<DeviceCodeStart, ApiError> {
        serde_json::from_value(self.call("POST", "auth/device-code", Some(json!({"client_name": client_name})))?).map_err(|e| ApiError::Network(e.to_string()))
    }

    /// One poll. Ok(None) while pending; Ok(Some((token, user name))) once approved.
    pub fn poll_device_code(&self, device_code: &str) -> Result<Option<(String, String)>, ApiError> {
        match self.call("POST", "auth/device-token", Some(json!({"device_code": device_code}))) {
            Ok(v) => Ok(Some((v["token"].as_str().unwrap_or_default().to_string(), v["user"]["name"].as_str().unwrap_or_default().to_string()))),
            Err(ApiError::Http { code, .. }) if code == "authorization_pending" || code == "slow_down" => Ok(None),
            Err(e) => Err(e),
        }
    }

    pub fn login(&self, email: &str, password: &str, device_name: &str) -> Result<(String, String), ApiError> {
        let v = self.call("POST", "auth/login", Some(json!({"email": email, "password": password, "device_name": device_name})))?;
        Ok((v["token"].as_str().unwrap_or_default().to_string(), v["user"]["name"].as_str().unwrap_or_default().to_string()))
    }

    pub fn me(&self) -> Result<String, ApiError> {
        Ok(self.call("GET", "me", None)?["user"]["name"].as_str().unwrap_or_default().to_string())
    }

    pub fn register_device(&self, name: &str, public_key: &str, app_version: &str) -> Result<String, ApiError> {
        let v = self.call("POST", "devices", Some(json!({"kind": "windows", "name": name, "public_key": public_key, "app_version": app_version, "os_version": std::env::consts::OS})))?;
        Ok(v["data"]["id"].as_str().unwrap_or_default().to_string())
    }

    pub fn library(&self) -> Result<Vec<LibraryItem>, ApiError> {
        serde_json::from_value(self.call("GET", "library", None)?["data"].clone()).map_err(|e| ApiError::Network(e.to_string()))
    }

    pub fn authorize_download(&self, package: &str, device: &str, from: Option<&str>) -> Result<DownloadGrant, ApiError> {
        let mut body = json!({"package": package, "device": device});
        if let Some(f) = from {
            body["from_version"] = json!(f);
        }
        let v = self.call("POST", "downloads", Some(body))?;
        Ok(DownloadGrant {
            id: v["download_id"].as_str().unwrap_or_default().into(),
            url: v["url"].as_str().unwrap_or_default().into(),
            kind: v["kind"].as_str().unwrap_or("full").into(),
            version: v["version"].as_str().unwrap_or_default().into(),
            sha256: v["sha256"].as_str().unwrap_or_default().into(),
            license_json: v["license"].to_string(),
        })
    }

    /// Signed URL — no bearer token.
    pub fn fetch(&self, url: &str) -> Result<Vec<u8>, ApiError> {
        let r = self.agent.get(url).call().map_err(|e| ApiError::Network(e.to_string()))?;
        let mut buf = Vec::new();
        std::io::Read::read_to_end(&mut r.into_reader(), &mut buf).map_err(|e| ApiError::Network(e.to_string()))?;
        Ok(buf)
    }

    pub fn complete_download(&self, id: &str, sha256: &str) -> Result<(), ApiError> {
        self.call("POST", &format!("downloads/{id}/complete"), Some(json!({"sha256": sha256}))).map(|_| ())
    }

    /// Returns refunded/revoked package ids.
    pub fn heartbeat(&self, device: &str, app_version: &str, installed: &[(String, String)]) -> Result<Vec<String>, ApiError> {
        let inst: Vec<Value> = installed.iter().map(|(p, v)| json!({"package": p, "version": v})).collect();
        let v = self.call("POST", &format!("devices/{device}/heartbeat"), Some(json!({"app_version": app_version, "installations": inst})))?;
        Ok(v["revoked"].as_array().map(|a| a.iter().filter_map(|x| x.as_str().map(String::from)).collect()).unwrap_or_default())
    }

    pub fn refresh_licenses(&self, device: &str) -> Result<Vec<(String, String)>, ApiError> {
        let v = self.call("POST", &format!("devices/{device}/licenses"), None)?;
        Ok(v["licenses"].as_object().map(|m| m.iter().map(|(k, l)| (k.clone(), l.to_string())).collect()).unwrap_or_default())
    }

    /// POST translation-reports. `entry_id` is the line id from the package (`Hit.entry_id`).
    pub fn report_line(&self, package: &str, version: Option<&str>, entry_id: i64, reason: &str, note: Option<&str>, source: &str) -> Result<(), ApiError> {
        let body = json!({"package": package, "version": version, "entry_id": entry_id, "reason": reason, "note": note, "source": source});
        self.call("POST", "translation-reports", Some(body)).map(|_| ())
    }
}
