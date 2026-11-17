import CryptoKit
import Foundation
import UIKit

enum OtaFunctions {
    private static let versionKey = "nativephp.custom_ota.version"
    private static let pendingVersionKey = "nativephp.custom_ota.pending_version"

    private static var otaDirectory: URL {
        let appSupport = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first!
        let dir = appSupport.appendingPathComponent("ota", isDirectory: true)
        try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        return dir
    }

    private static var previousZip: URL { otaDirectory.appendingPathComponent("previous.zip") }

    private static var updatesDirectory: URL {
        let docs = FileManager.default.urls(for: .documentDirectory, in: .userDomainMask).first!
        let dir = docs.appendingPathComponent("updates", isDirectory: true)
        try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        return dir
    }

    private static var pendingZip: URL { updatesDirectory.appendingPathComponent("pending.zip") }
    private static var pendingManifest: URL { updatesDirectory.appendingPathComponent("pending.json") }

    private static var installedVersion: String {
        get { UserDefaults.standard.string(forKey: versionKey) ?? "0.0.0" }
        set { UserDefaults.standard.set(newValue, forKey: versionKey) }
    }

    private static func loadBakedConfig() -> (publicKey: String, shellFingerprint: String) {
        guard let url = Bundle.main.url(forResource: "ota-config", withExtension: "json"),
              let data = try? Data(contentsOf: url),
              let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else {
            return ("", "")
        }
        return (
            json["public_key"] as? String ?? "",
            json["shell_fingerprint"] as? String ?? ""
        )
    }

    private static func isSecureUrl(_ url: URL, allowHttp: Bool) -> Bool {
        if url.scheme?.lowercased() == "https" { return true }
        return allowHttp && url.scheme?.lowercased() == "http"
    }

    private static func isNewerVersion(_ candidate: String, than current: String) -> Bool {
        let cParts = candidate.split(separator: ".").compactMap { Int($0) }
        let curParts = current.split(separator: ".").compactMap { Int($0) }
        let count = max(cParts.count, curParts.count)
        for i in 0..<count {
            let c = i < cParts.count ? cParts[i] : 0
            let cur = i < curParts.count ? curParts[i] : 0
            if c > cur { return true }
            if c < cur { return false }
        }
        return false
    }

    private static func fetchJson(url: URL, token: String) throws -> [String: Any] {
        var request = URLRequest(url: url)
        request.httpMethod = "GET"
        request.timeoutInterval = 30
        if !token.isEmpty {
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }

        let semaphore = DispatchSemaphore(value: 0)
        var resultData: Data?
        var statusCode = 0
        var reqError: Error?

        URLSession.shared.dataTask(with: request) { data, response, error in
            resultData = data
            statusCode = (response as? HTTPURLResponse)?.statusCode ?? 0
            reqError = error
            semaphore.signal()
        }.resume()
        semaphore.wait()

        if let reqError = reqError { throw reqError }
        guard (200...299).contains(statusCode), let data = resultData else {
            throw NSError(domain: "Ota", code: statusCode, userInfo: [NSLocalizedDescriptionKey: "HTTP \(statusCode)"])
        }
        guard let json = try JSONSerialization.jsonObject(with: data) as? [String: Any] else {
            throw NSError(domain: "Ota", code: -1, userInfo: [NSLocalizedDescriptionKey: "Invalid JSON response"])
        }
        return json
    }

    class GetStatus: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let fm = FileManager.default
            return [
                "current_version": OtaFunctions.installedVersion,
                "pending": fm.fileExists(atPath: OtaFunctions.pendingZip.path),
                "has_previous": fm.fileExists(atPath: OtaFunctions.previousZip.path),
                "pending_version": UserDefaults.standard.string(forKey: OtaFunctions.pendingVersionKey) ?? ""
            ]
        }
    }

    class Check: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let endpointStr = parameters["endpoint"] as? String,
                  var components = URLComponents(string: endpointStr),
                  let baseUrl = components.url else {
                return ["available": false, "error": "Invalid endpoint URL"]
            }

            let allowHttp = parameters["allow_insecure_http"] as? Bool ?? false
            guard OtaFunctions.isSecureUrl(baseUrl, allowHttp: allowHttp) else {
                return ["available": false, "error": "Insecure HTTP is not allowed"]
            }

            let channel = parameters["channel"] as? String ?? "production"
            let currentVersion = parameters["current_version"] as? String ?? OtaFunctions.installedVersion
            let token = parameters["token"] as? String ?? ""
            let baked = OtaFunctions.loadBakedConfig()
            let shellFingerprint = !baked.shellFingerprint.isEmpty
                ? baked.shellFingerprint
                : (parameters["shell_fingerprint"] as? String ?? "")

            if !endpointStr.hasSuffix(".json") {
                var queryItems = components.queryItems ?? []
                queryItems.append(URLQueryItem(name: "channel", value: channel))
                queryItems.append(URLQueryItem(name: "version", value: currentVersion))
                if !shellFingerprint.isEmpty {
                    queryItems.append(URLQueryItem(name: "shell_fingerprint", value: shellFingerprint))
                }
                components.queryItems = queryItems
            }

            guard let finalUrl = components.url else {
                return ["available": false, "error": "Failed to build request URL"]
            }

            do {
                let json = try OtaFunctions.fetchJson(url: finalUrl, token: token)
                let release: [String: Any]
                if let channels = json["channels"] as? [String: Any],
                   let channelData = channels[channel] as? [String: Any] {
                    release = channelData
                } else {
                    release = json
                }

                guard let version = release["version"] as? String,
                      let url = release["url"] as? String,
                      let sha256 = release["sha256"] as? String,
                      let signature = release["signature"] as? String else {
                    return ["available": false, "reason": "No release metadata for channel \(channel)"]
                }

                let releaseFingerprint = release["shell_fingerprint"] as? String ?? ""
                if !shellFingerprint.isEmpty && !releaseFingerprint.isEmpty && shellFingerprint != releaseFingerprint {
                    return ["available": false, "reason": "Shell fingerprint mismatch"]
                }

                guard OtaFunctions.isNewerVersion(version, than: currentVersion) else {
                    return ["available": false, "reason": "Already on latest version (\(currentVersion))"]
                }

                return [
                    "available": true,
                    "version": version,
                    "url": url,
                    "sha256": sha256,
                    "size": release["size"] as? Int ?? 0,
                    "signature": signature,
                    "shell_fingerprint": releaseFingerprint,
                    "mandatory": release["mandatory"] as? Bool ?? false,
                    "release_notes": release["release_notes"] as? String ?? ""
                ]
            } catch {
                return ["available": false, "error": error.localizedDescription]
            }
        }
    }

    class Download: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let urlStr = parameters["url"] as? String, let url = URL(string: urlStr) else {
                return ["success": false, "error": "Invalid download URL"]
            }

            let allowHttp = parameters["allow_insecure_http"] as? Bool ?? false
            guard OtaFunctions.isSecureUrl(url, allowHttp: allowHttp) else {
                return ["success": false, "error": "Insecure HTTP download rejected"]
            }

            let expectedSha256 = (parameters["sha256"] as? String ?? "").lowercased()
            let expectedSize = parameters["size"] as? Int ?? 0
            let signatureB64 = parameters["signature"] as? String ?? ""
            let version = parameters["version"] as? String ?? ""
            let token = parameters["token"] as? String ?? ""

            let baked = OtaFunctions.loadBakedConfig()
            let publicKeyB64 = baked.publicKey
            let shellFingerprint = !baked.shellFingerprint.isEmpty
                ? baked.shellFingerprint
                : (parameters["shell_fingerprint"] as? String ?? "")

            guard !publicKeyB64.isEmpty,
                  let pubKeyData = Data(base64Encoded: publicKeyB64),
                  let publicKey = try? Curve25519.Signing.PublicKey(rawRepresentation: pubKeyData) else {
                return ["success": false, "error": "Missing or invalid baked-in Ed25519 public key"]
            }

            guard let sigData = Data(base64Encoded: signatureB64) else {
                return ["success": false, "error": "Invalid base64 signature"]
            }

            var request = URLRequest(url: url)
            request.httpMethod = "GET"
            request.timeoutInterval = 120
            if !token.isEmpty {
                request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
            }

            let semaphore = DispatchSemaphore(value: 0)
            var downloadedData: Data?
            var statusCode = 0
            var reqError: Error?

            URLSession.shared.dataTask(with: request) { data, response, error in
                downloadedData = data
                statusCode = (response as? HTTPURLResponse)?.statusCode ?? 0
                reqError = error
                semaphore.signal()
            }.resume()
            semaphore.wait()

            if let reqError = reqError {
                return ["success": false, "error": reqError.localizedDescription]
            }
            guard (200...299).contains(statusCode), let data = downloadedData else {
                return ["success": false, "error": "Download failed with HTTP \(statusCode)"]
            }

            if expectedSize > 0 && data.count != expectedSize {
                return ["success": false, "error": "Size mismatch: expected \(expectedSize), got \(data.count)"]
            }

            let digest = SHA256.hash(data: data)
            let actualSha256 = digest.map { String(format: "%02x", $0) }.joined()
            guard actualSha256 == expectedSha256 else {
                return ["success": false, "error": "SHA-256 checksum mismatch"]
            }

            let canonical = "\(actualSha256)|\(version)|\(shellFingerprint)"
            guard let canonicalData = canonical.data(using: .utf8),
                  publicKey.isValidSignature(sigData, for: canonicalData) else {
                return ["success": false, "error": "Ed25519 signature verification failed"]
            }

            let fm = FileManager.default
            if fm.fileExists(atPath: OtaFunctions.pendingZip.path) {
                if fm.fileExists(atPath: OtaFunctions.previousZip.path) {
                    try? fm.removeItem(at: OtaFunctions.previousZip)
                }
                try? fm.copyItem(at: OtaFunctions.pendingZip, to: OtaFunctions.previousZip)
                try? fm.removeItem(at: OtaFunctions.pendingZip)
            }

            try data.write(to: OtaFunctions.pendingZip, options: .atomic)
            UserDefaults.standard.set(version, forKey: OtaFunctions.pendingVersionKey)

            return ["success": true, "version": version, "size": data.count]
        }
    }

    class Apply: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let fm = FileManager.default
            guard fm.fileExists(atPath: OtaFunctions.pendingZip.path) else {
                return ["success": false, "error": "No pending update staged"]
            }

            let version = (parameters["version"] as? String).flatMap { $0.isEmpty ? nil : $0 }
                ?? UserDefaults.standard.string(forKey: OtaFunctions.pendingVersionKey)
                ?? ""

            let manifest: [String: Any] = ["version": version, "queued_at": ISO8601DateFormatter().string(from: Date())]
            if let manifestData = try? JSONSerialization.data(withJSONObject: manifest) {
                try? manifestData.write(to: OtaFunctions.pendingManifest, options: .atomic)
            }

            if !version.isEmpty {
                OtaFunctions.installedVersion = version
            }

            return ["success": true, "version": version, "apply_on_next_boot": true]
        }
    }

    class Rollback: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let fm = FileManager.default
            guard fm.fileExists(atPath: OtaFunctions.previousZip.path) else {
                return ["success": false, "error": "No previous update available for rollback"]
            }

            if fm.fileExists(atPath: OtaFunctions.pendingZip.path) {
                try? fm.removeItem(at: OtaFunctions.pendingZip)
            }
            try fm.copyItem(at: OtaFunctions.previousZip, to: OtaFunctions.pendingZip)

            return ["success": true]
        }
    }

    class Prompt: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let checkResult = try Check().execute(parameters: parameters)
            guard (checkResult["available"] as? Bool) == true else {
                return ["prompted": false, "reason": checkResult["reason"] ?? "No update"]
            }

            let version = checkResult["version"] as? String ?? ""
            DispatchQueue.main.async {
                guard let scene = UIApplication.shared.connectedScenes.first as? UIWindowScene,
                      let root = scene.windows.first?.rootViewController else { return }

                let alert = UIAlertController(
                    title: "Update Available",
                    message: "Version \(version) is ready to install.",
                    preferredStyle: .alert
                )
                alert.addAction(UIAlertAction(title: "Later", style: .cancel))
                alert.addAction(UIAlertAction(title: "Update", style: .default) { _ in
                    DispatchQueue.global(qos: .userInitiated).async {
                        var dlParams = parameters
                        dlParams["url"] = checkResult["url"]
                        dlParams["sha256"] = checkResult["sha256"]
                        dlParams["size"] = checkResult["size"]
                        dlParams["signature"] = checkResult["signature"]
                        dlParams["version"] = version
                        _ = try? Download().execute(parameters: dlParams)
                        _ = try? Apply().execute(parameters: ["version": version])
                    }
                })
                root.present(alert, animated: true)
            }

            return ["prompted": true, "version": version]
        }
    }
}

