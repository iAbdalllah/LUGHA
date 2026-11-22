//! Headless tests of the Windows app's core (run on any OS: `cargo test --no-default-features`).

use std::io::{Read, Write};
use std::net::{TcpListener, TcpStream};
use std::time::{Duration, Instant};

use lugha_link::{frame, Message, ReceiverPairing, Session, StaticKeys};
use lugha_windows::pipeline::Pipeline;
use lugha_windows::store::PackageStore;
use lugha_windows::tv::{TvError, TvSender};

fn lookup(text: &str) -> Option<(String, Option<String>)> {
    match text {
        "Hello there." => Some(("أهلاً بك.".into(), Some("Jack".into()))),
        "Let's go." => Some(("يلا نمشي.".into(), None)),
        _ => None,
    }
}

#[test]
fn pipeline_waits_for_stable_frames_then_hides_after_silence() {
    let mut p = Pipeline::default();
    let t0 = Instant::now();
    let l = |s: &str| vec![s.to_string()];
    assert!(!p.ingest(&l("Hello there."), t0, lookup), "one frame is not enough");
    assert!(p.ingest(&l("Hello there."), t0 + Duration::from_millis(100), lookup));
    assert_eq!(p.current.as_ref().unwrap().text, "أهلاً بك.");
    assert_eq!(p.current.as_ref().unwrap().speaker.as_deref(), Some("Jack"));
    // Same text again: no change.
    assert!(!p.ingest(&l("Hello there."), t0 + Duration::from_millis(200), lookup));
    // Brief gap below hide_after keeps it.
    assert!(!p.ingest(&[], t0 + Duration::from_millis(800), lookup));
    assert!(p.current.is_some());
    // Long silence hides it.
    assert!(p.ingest(&[], t0 + Duration::from_millis(1500), lookup));
    assert!(p.current.is_none());
    // Next line.
    p.ingest(&l("Let's go."), t0 + Duration::from_millis(1600), lookup);
    p.ingest(&l("Let's go."), t0 + Duration::from_millis(1700), lookup);
    assert_eq!(p.current.as_ref().unwrap().text, "يلا نمشي.");
    assert_eq!(p.history.len(), 2);
}

#[test]
fn pipeline_ignores_noise_and_unknown_lines() {
    let mut p = Pipeline::default();
    let t = Instant::now();
    p.ingest(&["  ".into(), "".into()], t, lookup);
    assert!(p.current.is_none());
    p.ingest(&["Unknown".into()], t, lookup);
    assert!(!p.ingest(&["Unknown".into()], t, lookup));
    assert!(p.current.is_none());
}

#[test]
fn store_lists_installed_manifests() {
    let dir = std::env::temp_dir().join(format!("lugha-win-store-{}", std::process::id()));
    let store = PackageStore::new(Some(dir.clone())).unwrap();
    std::fs::create_dir_all(store.dir_for("demo-full")).unwrap();
    std::fs::write(store.dir_for("demo-full").join("manifest.json"), r#"{"package_id":"demo-full","version":"1.2.0"}"#).unwrap();
    std::fs::create_dir_all(store.dir_for("broken")).unwrap();
    assert_eq!(store.installed(), vec![("demo-full".to_string(), "1.2.0".to_string())]);
    store.remove("demo-full");
    assert!(store.installed().is_empty());
    let _ = std::fs::remove_dir_all(dir);
}

// ---- A minimal LUGHA TV receiver (same lugha-link crypto the Android TV app uses) ----

fn wf(s: &mut TcpStream, t: u8, p: &[u8]) {
    let mut b = ((p.len() + 1) as u32).to_be_bytes().to_vec();
    b.push(t);
    b.extend_from_slice(p);
    s.write_all(&b).unwrap();
}

fn rf(s: &mut TcpStream) -> Option<(u8, Vec<u8>)> {
    let mut len = [0u8; 4];
    s.read_exact(&mut len).ok()?;
    let mut body = vec![0u8; u32::from_be_bytes(len) as usize];
    s.read_exact(&mut body).ok()?;
    Some((body[0], body[1..].to_vec()))
}

/// Accepts connections; pairs with `code`, then serves sessions to paired senders. Returns shown lines via channel.
fn fake_tv(code: &'static str) -> (String, [u8; 32], std::sync::mpsc::Receiver<String>) {
    let listener = TcpListener::bind("127.0.0.1:0").unwrap();
    let addr = listener.local_addr().unwrap().to_string();
    let tv = StaticKeys::generate();
    let tv_public = tv.public;
    let (tx, rx) = std::sync::mpsc::channel();
    std::thread::spawn(move || {
        let mut paired: Vec<[u8; 32]> = vec![];
        for conn in listener.incoming() {
            let mut s = conn.unwrap();
            let Some((mut t, mut body)) = rf(&mut s) else { continue };
            if t == frame::PAIR1 {
                let mut rp = ReceiverPairing::new(code, tv.clone());
                let m2 = rp.respond(&body).unwrap();
                wf(&mut s, frame::PAIR2, &m2);
                let Some((_, m3)) = rf(&mut s) else { continue };
                match rp.finish(&m3) {
                    Ok(sender) => {
                        paired.push(sender);
                        wf(&mut s, frame::PAIR_OK, &[]);
                    }
                    Err(_) => {
                        wf(&mut s, frame::PAIR_FAIL, &[]);
                        continue;
                    }
                }
                let Some(next) = rf(&mut s) else { continue };
                (t, body) = next;
            }
            assert_eq!(t, frame::HS1);
            let known = paired.clone();
            let Ok((mut session, hs2, _peer)) = Session::accept(&tv, &body, |k| known.contains(k)) else {
                continue; // unpaired: drop
            };
            wf(&mut s, frame::HS2, &hs2);
            while let Some((t, c)) = rf(&mut s) {
                assert_eq!(t, frame::DATA);
                let m = Message::from_json(&session.decrypt(&c).unwrap()).unwrap();
                let reply = match m {
                    Message::Hello { .. } => Some(Message::Welcome { tv_name: "Test TV".into(), screen_w: 1920, screen_h: 1080 }),
                    Message::Show { id, text, ack, .. } => {
                        tx.send(text).unwrap();
                        ack.then_some(Message::Ack { id, painted_ms: 0 })
                    }
                    Message::Clear { .. } => {
                        tx.send(String::new()).unwrap();
                        None
                    }
                    Message::Ping { id } => Some(Message::Pong { id }),
                    _ => None,
                };
                if let Some(r) = reply {
                    let e = session.encrypt(&r.to_json()).unwrap();
                    wf(&mut s, frame::DATA, &e);
                }
            }
        }
    });
    (addr, tv_public, rx)
}

#[test]
fn tv_pair_show_reconnect() {
    let (addr, tv_public, rx) = fake_tv("482913");
    let me = StaticKeys::generate();

    let mut tv = TvSender::pair(&addr, "482913", &me, "Test PC").expect("pair");
    assert_eq!(tv.tv_public, tv_public, "pairing learns the TV's real key");
    let rtt = tv.show("أهلاً بك.", Some("Jack"), true).unwrap().unwrap();
    assert_eq!(rx.recv_timeout(Duration::from_secs(5)).unwrap(), "أهلاً بك.");
    assert!(rtt < Duration::from_secs(1));
    assert!(tv.ping().unwrap());
    tv.clear().unwrap();
    assert_eq!(rx.recv_timeout(Duration::from_secs(5)).unwrap(), "");
    drop(tv);

    // Later sessions: no code, stored key.
    let mut again = TvSender::connect(&addr, &me, tv_public, "Test PC").expect("reconnect");
    again.show("يلا نمشي.", None, true).unwrap();
    assert_eq!(rx.recv_timeout(Duration::from_secs(5)).unwrap(), "يلا نمشي.");

    // Latency over loopback (encrypt → TCP → decrypt → ack).
    let mut samples: Vec<Duration> = (0..50).map(|i| again.show(&format!("سطر {i}"), None, true).unwrap().unwrap()).collect();
    samples.sort();
    let p95 = samples[47];
    eprintln!("loopback show→ack p50 {:?} p95 {:?}", samples[25], p95);
    assert!(p95 < Duration::from_millis(50));
}

#[test]
fn tv_wrong_code_and_unpaired_sender_are_refused() {
    let (addr, tv_public, _rx) = fake_tv("111111");
    let me = StaticKeys::generate();
    assert!(matches!(TvSender::pair(&addr, "222222", &me, "PC"), Err(TvError::WrongCode)));
    let stranger = StaticKeys::generate();
    assert!(TvSender::connect(&addr, &stranger, tv_public, "PC").is_err());
}
