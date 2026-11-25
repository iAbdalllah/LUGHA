//! PSN sign-in for Remote Play registration: the player signs in on Sony's own page (the app shows it in a web view,
//! LUGHA never sees the password), Sony redirects to [`REDIRECT`] with a one-time code, and the code gives the
//! account's user id, which the console needs to link this device. Same flow and public client as chiaki-ng
//! (gui/include/psnaccountid.h) and LUGHA's phone app.

use base64::Engine;

const CLIENT_ID: &str = "ba495a24-818c-472b-b12d-ff231c1b5745";
const CLIENT_SECRET: &str = "mvaiZkRsAsI1IBkY";
const SCOPE: &str = "psn:clientapp referenceDataService:countryConfig.read pushNotification:webSocket.desktop.connect sessionManager:remotePlaySession.system.update";
const TOKEN_URL: &str = "https://auth.api.sonyentertainmentnetwork.com/2.0/oauth/token";

/// Where Sony sends the browser after sign-in; the web view stops there and passes the URL to [`code_from_redirect`].
pub const REDIRECT: &str = "https://remoteplay.dl.playstation.net/remoteplay/redirect";

fn enc(s: &str) -> String {
    s.bytes()
        .map(|b| match b {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' | b'~' => (b as char).to_string(),
            _ => format!("%{b:02X}"),
        })
        .collect()
}

/// Sony's sign-in page.
pub fn authorize_url() -> String {
    let q = [
        ("service_entity", "urn:service-entity:psn"),
        ("response_type", "code"),
        ("client_id", CLIENT_ID),
        ("redirect_uri", REDIRECT),
        ("scope", SCOPE),
        ("request_locale", "en_US"),
        ("ui", "pr"),
        ("service_logo", "ps"),
        ("layout_type", "popup"),
        ("smcid", "remoteplay"),
        ("prompt", "always"),
        ("PlatformPrivacyWs1", "minimal"),
    ];
    let q: Vec<String> = q.iter().map(|(k, v)| format!("{k}={}", enc(v))).collect();
    format!("https://auth.api.sonyentertainmentnetwork.com/2.0/oauth/authorize?{}", q.join("&"))
}

/// The one-time code in Sony's redirect URL, or None for any other page.
pub fn code_from_redirect(url: &str) -> Option<String> {
    let rest = url.strip_prefix(REDIRECT)?;
    let query = rest.split_once('?')?.1;
    query.split('&').find_map(|kv| kv.strip_prefix("code=")).map(|c| c.split('#').next().unwrap_or(c).to_string())
}

#[derive(Debug, thiserror::Error)]
pub enum PsnError {
    #[error("PSN: {0}")]
    Http(String),
    #[error("PSN sent an unexpected answer")]
    Answer,
}

/// Exchanges the code for the account id (8 bytes, little-endian), what [`crate::register`] needs.
pub fn account_id_from_code(code: &str) -> Result<[u8; 8], PsnError> {
    let basic = format!("Basic {}", base64::engine::general_purpose::STANDARD.encode(format!("{CLIENT_ID}:{CLIENT_SECRET}")));
    let body = format!("grant_type=authorization_code&code={}&scope={}&redirect_uri={}", enc(code), enc(SCOPE), enc(REDIRECT));
    let tok: serde_json::Value = ureq::post(TOKEN_URL)
        .set("Authorization", &basic)
        .set("Content-Type", "application/x-www-form-urlencoded")
        .send_string(&body)
        .map_err(|e| PsnError::Http(e.to_string()))?
        .into_json()
        .map_err(|_| PsnError::Answer)?;
    let access = tok["access_token"].as_str().ok_or(PsnError::Answer)?;
    let info: serde_json::Value = ureq::get(&format!("{TOKEN_URL}/{access}"))
        .set("Authorization", &basic)
        .set("Accept", "application/json")
        .call()
        .map_err(|e| PsnError::Http(e.to_string()))?
        .into_json()
        .map_err(|_| PsnError::Answer)?;
    let user_id = match &info["user_id"] {
        serde_json::Value::String(s) => s.clone(),
        serde_json::Value::Number(n) => n.to_string(),
        _ => return Err(PsnError::Answer),
    };
    user_id.parse::<u64>().map(u64::to_le_bytes).map_err(|_| PsnError::Answer)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn finds_the_code_only_in_sonys_redirect() {
        assert_eq!(code_from_redirect(&format!("{REDIRECT}?code=v3.AbC&cid=x")).as_deref(), Some("v3.AbC"));
        assert_eq!(code_from_redirect(&format!("{REDIRECT}?cid=x&code=Z9")).as_deref(), Some("Z9"));
        assert_eq!(code_from_redirect("https://example.com/?code=nope"), None);
        assert_eq!(code_from_redirect(REDIRECT), None);
    }

    #[test]
    fn sign_in_page_carries_the_client_and_redirect() {
        let u = authorize_url();
        assert!(u.starts_with("https://auth.api.sonyentertainmentnetwork.com/2.0/oauth/authorize?"));
        assert!(u.contains(CLIENT_ID));
        assert!(u.contains("redirect_uri=https%3A%2F%2Fremoteplay.dl.playstation.net%2Fremoteplay%2Fredirect"));
    }
}
