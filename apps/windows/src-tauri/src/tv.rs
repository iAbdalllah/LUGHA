//! LUGHA Link sender (docs/05) over std TCP — Windows PC → LUGHA TV.

use std::io::{Read, Write};
use std::net::TcpStream;
use std::time::{Duration, Instant};

use lugha_link::{frame, Message, SenderPairing, Session, StaticKeys};

#[derive(Debug, thiserror::Error)]
pub enum TvError {
    #[error("wrong code")]
    WrongCode,
    #[error("pairing locked — try again in a minute")]
    Locked,
    #[error("{0}")]
    Protocol(String),
    #[error("io: {0}")]
    Io(#[from] std::io::Error),
}

fn write_frame(s: &mut TcpStream, t: u8, payload: &[u8]) -> std::io::Result<()> {
    let mut buf = Vec::with_capacity(payload.len() + 5);
    buf.extend_from_slice(&((payload.len() + 1) as u32).to_be_bytes());
    buf.push(t);
    buf.extend_from_slice(payload);
    s.write_all(&buf)
}

fn read_frame(s: &mut TcpStream) -> std::io::Result<(u8, Vec<u8>)> {
    let mut len = [0u8; 4];
    s.read_exact(&mut len)?;
    let len = u32::from_be_bytes(len) as usize;
    if !(1..=lugha_link::MAX_FRAME).contains(&len) {
        return Err(std::io::Error::new(std::io::ErrorKind::InvalidData, "bad frame"));
    }
    let mut body = vec![0u8; len];
    s.read_exact(&mut body)?;
    Ok((body[0], body[1..].to_vec()))
}

pub struct TvSender {
    stream: TcpStream,
    session: Session,
    next_id: u64,
    pub tv_public: [u8; 32],
}

impl TvSender {
    fn open(addr: &str) -> Result<TcpStream, TvError> {
        let sock = addr.parse().map_err(|_| TvError::Protocol(format!("bad address {addr}")))?;
        let s = TcpStream::connect_timeout(&sock, Duration::from_secs(6))?;
        s.set_nodelay(true)?;
        s.set_read_timeout(Some(Duration::from_secs(15)))?;
        Ok(s)
    }

    /// First-time pairing with the 6-digit code on the TV; the connection then continues as a session.
    pub fn pair(addr: &str, code: &str, me: &StaticKeys, sender_name: &str) -> Result<Self, TvError> {
        let mut s = Self::open(addr)?;
        let (mut p, m1) = SenderPairing::start(code, me.clone());
        write_frame(&mut s, frame::PAIR1, &m1)?;
        let (t2, m2) = read_frame(&mut s)?;
        if t2 == frame::PAIR_FAIL {
            return Err(TvError::Locked);
        }
        let (m3, tv_public) = match p.finish(&m2) {
            Ok(v) => v,
            Err(_) => {
                let _ = write_frame(&mut s, frame::PAIR3, &[0u8; 48]);
                return Err(TvError::WrongCode);
            }
        };
        write_frame(&mut s, frame::PAIR3, &m3)?;
        if read_frame(&mut s)?.0 != frame::PAIR_OK {
            return Err(TvError::WrongCode);
        }
        Self::handshake(s, me, tv_public, sender_name)
    }

    pub fn connect(addr: &str, me: &StaticKeys, tv_public: [u8; 32], sender_name: &str) -> Result<Self, TvError> {
        Self::handshake(Self::open(addr)?, me, tv_public, sender_name)
    }

    fn handshake(mut s: TcpStream, me: &StaticKeys, tv_public: [u8; 32], sender_name: &str) -> Result<Self, TvError> {
        let (mut session, hs1) = Session::initiate(me, &tv_public).map_err(|e| TvError::Protocol(e.to_string()))?;
        write_frame(&mut s, frame::HS1, &hs1)?;
        let (t, hs2) = read_frame(&mut s)?;
        if t != frame::HS2 {
            return Err(TvError::Protocol("TV refused the session (not paired?)".into()));
        }
        session.complete(&hs2).map_err(|e| TvError::Protocol(e.to_string()))?;
        let mut me = Self { stream: s, session, next_id: 1, tv_public };
        me.send(&Message::Hello { app: "lugha-windows".into(), app_version: env!("CARGO_PKG_VERSION").into(), sender_name: sender_name.into() })?;
        me.recv()?; // welcome
        Ok(me)
    }

    fn send(&mut self, m: &Message) -> Result<(), TvError> {
        let c = self.session.encrypt(&m.to_json()).map_err(|e| TvError::Protocol(e.to_string()))?;
        Ok(write_frame(&mut self.stream, frame::DATA, &c)?)
    }

    fn recv(&mut self) -> Result<Message, TvError> {
        let (t, body) = read_frame(&mut self.stream)?;
        if t != frame::DATA {
            return Err(TvError::Protocol("unexpected frame".into()));
        }
        let p = self.session.decrypt(&body).map_err(|e| TvError::Protocol(e.to_string()))?;
        Message::from_json(&p).map_err(|e| TvError::Protocol(e.to_string()))
    }

    fn id(&mut self) -> u64 {
        self.next_id += 1;
        self.next_id - 1
    }

    /// Show a line; with `wait_for_ack`, returns the round trip until the TV painted it.
    pub fn show(&mut self, text: &str, speaker: Option<&str>, wait_for_ack: bool) -> Result<Option<Duration>, TvError> {
        let id = self.id();
        let t0 = Instant::now();
        self.send(&Message::Show { id, sid: id, text: text.into(), speaker: speaker.map(String::from), dir: Some("rtl".into()), ack: wait_for_ack, ts: 0 })?;
        if !wait_for_ack {
            return Ok(None);
        }
        loop {
            if let Message::Ack { id: got, .. } = self.recv()? {
                if got == id {
                    return Ok(Some(t0.elapsed()));
                }
            }
        }
    }

    pub fn clear(&mut self) -> Result<(), TvError> {
        let id = self.id();
        self.send(&Message::Clear { id })
    }

    pub fn ping(&mut self) -> Result<bool, TvError> {
        let id = self.id();
        self.send(&Message::Ping { id })?;
        Ok(matches!(self.recv()?, Message::Pong { .. }))
    }
}
