// PSN sign-in for linking the PS5: the player signs in on Sony's own page (LUGHA never sees the password), Sony
// redirects with a one-time code, and the code gives the account's user id, which the PS5 needs. Same flow and public
// client as chiaki-ng (gui/include/psnaccountid.h, AGPL-3.0) and LUGHA's other apps.

import Foundation

public enum PSN {
    private static let clientID = "ba495a24-818c-472b-b12d-ff231c1b5745"
    private static let clientSecret = "mvaiZkRsAsI1IBkY"
    private static let scope = "psn:clientapp referenceDataService:countryConfig.read pushNotification:webSocket.desktop.connect sessionManager:remotePlaySession.system.update"
    private static let tokenURL = "https://auth.api.sonyentertainmentnetwork.com/2.0/oauth/token"

    /// Where Sony sends the browser after sign-in; the web view stops there.
    public static let redirect = "https://remoteplay.dl.playstation.net/remoteplay/redirect"

    public static var authorizeURL: URL {
        var c = URLComponents(string: "https://auth.api.sonyentertainmentnetwork.com/2.0/oauth/authorize")!
        c.queryItems = [
            ("service_entity", "urn:service-entity:psn"), ("response_type", "code"), ("client_id", clientID), ("redirect_uri", redirect),
            ("scope", scope), ("request_locale", "en_US"), ("ui", "pr"), ("service_logo", "ps"), ("layout_type", "popup"),
            ("smcid", "remoteplay"), ("prompt", "always"), ("PlatformPrivacyWs1", "minimal"),
        ].map { URLQueryItem(name: $0.0, value: $0.1) }
        return c.url!
    }

    /// The one-time code in Sony's redirect, or nil for any other page.
    public static func code(from url: URL) -> String? {
        guard url.absoluteString.hasPrefix(redirect) else { return nil }
        return URLComponents(url: url, resolvingAgainstBaseURL: false)?.queryItems?.first { $0.name == "code" }?.value
    }

    private static func form(_ s: String) -> String {
        var allowed = CharacterSet.alphanumerics
        allowed.insert(charactersIn: "-._~")
        return s.addingPercentEncoding(withAllowedCharacters: allowed) ?? s
    }

    /// Exchanges the code for the account id the PS5 wants: base64 of the user id as 8 little-endian bytes.
    public static func accountID(code: String) async throws -> String {
        let basic = "Basic " + Data("\(clientID):\(clientSecret)".utf8).base64EncodedString()
        var tok = URLRequest(url: URL(string: tokenURL)!)
        tok.httpMethod = "POST"
        tok.setValue(basic, forHTTPHeaderField: "Authorization")
        tok.setValue("application/x-www-form-urlencoded", forHTTPHeaderField: "Content-Type")
        tok.httpBody = Data("grant_type=authorization_code&code=\(form(code))&scope=\(form(scope))&redirect_uri=\(form(redirect))".utf8)
        let (data, resp) = try await URLSession.shared.data(for: tok)
        guard (resp as? HTTPURLResponse)?.statusCode == 200,
              let access = (try JSONSerialization.jsonObject(with: data) as? [String: Any])?["access_token"] as? String
        else { throw PS5.Failure(message: "PSN sign-in failed (token).") }
        var info = URLRequest(url: URL(string: "\(tokenURL)/\(access)")!)
        info.setValue(basic, forHTTPHeaderField: "Authorization")
        info.setValue("application/json", forHTTPHeaderField: "Accept")
        let (d2, r2) = try await URLSession.shared.data(for: info)
        guard (r2 as? HTTPURLResponse)?.statusCode == 200, let j = try JSONSerialization.jsonObject(with: d2) as? [String: Any] else {
            throw PS5.Failure(message: "PSN sign-in failed (account).")
        }
        let userID: UInt64? = (j["user_id"] as? String).flatMap(UInt64.init) ?? (j["user_id"] as? NSNumber)?.uint64Value
        guard var v = userID else { throw PS5.Failure(message: "PSN sent no account id.") }
        var bytes = [UInt8]()
        for _ in 0 ..< 8 { bytes.append(UInt8(v & 0xFF)); v >>= 8 }
        return Data(bytes).base64EncodedString()
    }
}
