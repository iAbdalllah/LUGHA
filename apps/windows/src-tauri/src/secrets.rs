//! Secrets (API token, device key, TV link key) in Windows Credential Manager, scoped to the signed-in user.

use windows::core::{HSTRING, PWSTR};
use windows::Win32::Security::Credentials::{CredDeleteW, CredFree, CredReadW, CredWriteW, CREDENTIALW, CRED_PERSIST_LOCAL_MACHINE, CRED_TYPE_GENERIC};

fn target(key: &str) -> HSTRING {
    HSTRING::from(format!("LUGHA/{key}"))
}

pub fn set(key: &str, value: &str) -> windows::core::Result<()> {
    let t = target(key);
    let mut blob = value.as_bytes().to_vec();
    let cred = CREDENTIALW {
        Type: CRED_TYPE_GENERIC,
        TargetName: PWSTR(t.as_ptr() as *mut u16),
        CredentialBlobSize: blob.len() as u32,
        CredentialBlob: blob.as_mut_ptr(),
        Persist: CRED_PERSIST_LOCAL_MACHINE,
        ..Default::default()
    };
    unsafe { CredWriteW(&cred, 0) }
}

pub fn get(key: &str) -> Option<String> {
    let mut p: *mut CREDENTIALW = std::ptr::null_mut();
    unsafe {
        CredReadW(&target(key), CRED_TYPE_GENERIC, None, &mut p).ok()?;
        let c = &*p;
        let bytes = std::slice::from_raw_parts(c.CredentialBlob, c.CredentialBlobSize as usize).to_vec();
        CredFree(p as *const core::ffi::c_void);
        String::from_utf8(bytes).ok()
    }
}

pub fn delete(key: &str) {
    unsafe {
        let _ = CredDeleteW(&target(key), CRED_TYPE_GENERIC, None);
    }
}
