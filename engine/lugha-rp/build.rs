//! Builds native/ (chiaki-ng + the flat C API) with CMake and links every static library it produced.
//! Needs CMake, and Python 3 with the `protobuf` module (chiaki's nanopb step). `LUGHA_RP_NO_NATIVE=1` skips the
//! native build, for type-checking on a machine that cannot build it.
use std::path::{Path, PathBuf};

fn libs(dir: &Path, out: &mut Vec<PathBuf>) {
    let Ok(entries) = std::fs::read_dir(dir) else { return };
    for e in entries.flatten() {
        let p = e.path();
        let name = p.file_name().and_then(|n| n.to_str()).unwrap_or("");
        if p.is_dir() {
            // CMake's own scratch builds (FetchContent "subbuild", compiler checks) hold no libraries we want.
            if !name.ends_with("-subbuild") && name != "CMakeFiles" { libs(&p, out) }
        } else if name.ends_with(".a") || name.ends_with(".lib") {
            out.push(p);
        }
    }
}

fn main() {
    println!("cargo:rerun-if-changed=native/lugha_rp.c");
    println!("cargo:rerun-if-changed=native/lugha_rp.h");
    println!("cargo:rerun-if-changed=native/CMakeLists.txt");
    println!("cargo:rerun-if-env-changed=LUGHA_RP_NO_NATIVE");
    println!("cargo:rerun-if-env-changed=LUGHA_RP_CL");
    if std::env::var_os("LUGHA_RP_NO_NATIVE").is_some() {
        return;
    }
    let target = std::env::var("TARGET").unwrap();
    let mut cfg = cmake::Config::new("native");
    cfg.build_target("lugha_rp").profile("Release").define("CMAKE_POLICY_VERSION_MINIMUM", "3.5");
    if target.contains("apple") {
        cfg.define("CMAKE_OSX_DEPLOYMENT_TARGET", if target.contains("ios") { "15.0" } else { "13.0" });
    }
    if target.contains("msvc") {
        // chiaki-ng is C99 (variable-length arrays), which MSVC's cl cannot compile: build with Visual Studio's
        // clang-cl toolset, as chiaki-ng's own Windows builds do. Same ABI, links with the Rust side as usual.
        if std::env::var_os("LUGHA_RP_CL").is_none() {
            cfg.generator_toolset("ClangCL");
        }
        // Match Rust's release runtime (/MD) everywhere, also for the fetched projects.
        cfg.define("CMAKE_MSVC_RUNTIME_LIBRARY", "MultiThreadedDLL").define("CMAKE_POLICY_DEFAULT_CMP0091", "NEW");
    }
    let dst = cfg.build();

    let mut found = Vec::new();
    libs(&dst.join("build"), &mut found);
    // Our wrapper first, then chiaki, then what they use (the order matters for GNU-style linkers).
    let rank = |p: &PathBuf| {
        let n = p.file_stem().unwrap().to_string_lossy().to_string();
        let n = n.trim_start_matches("lib").to_string();
        let order = ["lugha_rp", "chiaki", "jerasure", "gf_complete", "protobuf-nanopb", "curl", "json-c", "miniupnpc",
            "event_extra", "event_core", "mbedtls", "mbedx509", "mbedcrypto"];
        order.iter().position(|o| n.starts_with(o)).unwrap_or(order.len())
    };
    found.sort_by_key(rank);
    for lib in &found {
        let stem = lib.file_stem().unwrap().to_string_lossy().to_string();
        let name = if target.contains("msvc") { stem } else { stem.trim_start_matches("lib").to_string() };
        if rank(lib) == 13 { continue } // anything we did not ask for (e.g. libevent's extra variants)
        println!("cargo:rustc-link-search=native={}", lib.parent().unwrap().display());
        println!("cargo:rustc-link-lib=static={name}");
    }
    if target.contains("windows") {
        for l in ["ws2_32", "iphlpapi", "bcrypt", "crypt32", "advapi32", "secur32", "normaliz", "wldap32"] {
            println!("cargo:rustc-link-lib={l}");
        }
    } else if target.contains("apple") {
        for f in ["CoreFoundation", "CoreServices", "SystemConfiguration", "Security"] {
            println!("cargo:rustc-link-lib=framework={f}");
        }
    }
}
