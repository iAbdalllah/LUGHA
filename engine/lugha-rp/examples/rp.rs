//! Developer tool for Remote Play with a real console:
//!   cargo run -p lugha-rp --example rp -- discover
//!   cargo run -p lugha-rp --example rp -- register <host> <account-id> <pin>     (prints the registration)
//!   cargo run -p lugha-rp --example rp -- watch <host> <registration> <account-id> (saves a PNG every 2 s)
use std::time::Duration;

fn main() {
    lugha_rp::set_log(|level, m| if level >= 4 { eprintln!("[{level}] {m}") });
    let a: Vec<String> = std::env::args().skip(1).collect();
    match a.first().map(String::as_str) {
        Some("discover") => {
            let _d = lugha_rp::Discovery::start(|list| for c in list { println!("{c:?}") }).unwrap();
            std::thread::sleep(Duration::from_secs(6));
        }
        Some("register") => {
            let id = lugha_rp::account_id(&a[2]).unwrap();
            let r = lugha_rp::register(&a[1], id, a[3].parse().unwrap(), true).unwrap();
            println!("{} {} {}", r.to_string_compact(), r.nickname, r.mac);
        }
        Some("watch") => {
            let reg = lugha_rp::Registration::from_string_compact(&a[2], "", "").expect("registration");
            let opts = lugha_rp::Options {
                host: a[1].clone(),
                ps5: true,
                account_id: lugha_rp::account_id(&a[3]).unwrap(),
                registration: reg,
                height: 720,
                pictures_per_second: 0.5,
            };
            let n = std::sync::atomic::AtomicU32::new(0);
            let _s = lugha_rp::Session::start(
                &opts,
                move |p| {
                    let i = n.fetch_add(1, std::sync::atomic::Ordering::Relaxed);
                    let path = format!("rp-{i:03}.png");
                    let f = std::fs::File::create(&path).unwrap();
                    let mut e = png::Encoder::new(std::io::BufWriter::new(f), p.width, p.height);
                    e.set_color(png::ColorType::Rgba);
                    e.write_header().unwrap().write_image_data(&p.rgba).unwrap();
                    println!("saved {path} ({}x{})", p.width, p.height);
                },
                |s| println!("state: {s:?}"),
            )
            .unwrap();
            std::thread::sleep(Duration::from_secs(20));
        }
        _ => eprintln!("usage: rp discover | register <host> <account-id> <pin> | watch <host> <registration> <account-id>"),
    }
}
