//! Developer check: does a session object start (no console needed; it fails later when nobody answers)?
fn main() {
    lugha_rp::set_log(|level, m| eprintln!("[{level}] {m}"));
    let reg = lugha_rp::Registration { regist_key: *b"1a2b3c4d\0\0\0\0\0\0\0\0", morning: [7; 16], nickname: String::new(), mac: String::new() };
    let opts = lugha_rp::Options { host: std::env::args().nth(1).unwrap_or("192.168.8.110".into()), ps5: true, account_id: [0; 8], registration: reg, height: 720, pictures_per_second: 1.0 };
    match lugha_rp::Session::start_raw(&opts, |_| {}, |s| eprintln!("state {s:?}")) {
        Ok(_s) => { eprintln!("started"); std::thread::sleep(std::time::Duration::from_secs(3)); }
        Err(e) => eprintln!("error: {e}"),
    }
}
