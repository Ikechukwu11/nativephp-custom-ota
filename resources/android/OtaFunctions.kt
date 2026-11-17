package com.nativephp.plugins.custom_ota

import android.app.AlertDialog
import android.content.Context
import android.os.Handler
import android.os.Looper
import android.util.Base64
import org.json.JSONObject
import java.io.File
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder
import java.security.KeyFactory
import java.security.MessageDigest
import java.security.Signature
import java.security.spec.X509EncodedKeySpec

object OtaFunctions {
    private const val PREFS_NAME = "nativephp_custom_ota"
    private const val KEY_VERSION = "version"
    private const val KEY_PENDING_VERSION = "pending_version"

    // ASN.1 X.509 SubjectPublicKeyInfo prefix for raw 32-byte Ed25519 public key
    private val ED25519_X509_PREFIX = byteArrayOf(
        0x30, 0x2a, 0x30, 0x05, 0x06, 0x03, 0x2b, 0x65, 0x70, 0x03, 0x21, 0x00
    )

    private fun updatesDir(context: Context): File =
        File(context.filesDir, "updates").apply { mkdirs() }

    private fun otaDir(context: Context): File =
        File(context.filesDir, "ota").apply { mkdirs() }

    private fun pendingZip(context: Context): File = File(updatesDir(context), "pending.zip")
    private fun pendingManifest(context: Context): File = File(updatesDir(context), "pending.json")
    private fun previousZip(context: Context): File = File(otaDir(context), "previous.zip")

    private fun loadBakedConfig(context: Context): Pair<String, String> {
        return try {
            val text = context.assets.open("ota-config.json").bufferedReader().use { it.readText() }
            val json = JSONObject(text)
            Pair(json.optString("public_key", ""), json.optString("shell_fingerprint", ""))
        } catch (_: Exception) {
            Pair("", "")
        }
    }

    private fun isSecureUrl(urlStr: String, allowHttp: Boolean): Boolean {
        val lower = urlStr.lowercase()
        return lower.startsWith("https://") || (allowHttp && lower.startsWith("http://"))
    }

    private fun isNewerVersion(candidate: String, current: String): Boolean {
        val cParts = candidate.split(".").mapNotNull { it.toIntOrNull() }
        val curParts = current.split(".").mapNotNull { it.toIntOrNull() }
        val count = maxOf(cParts.size, curParts.size)
        for (i in 0 until count) {
            val c = if (i < cParts.size) cParts[i] else 0
            val cur = if (i < curParts.size) curParts[i] else 0
            if (c > cur) return true
            if (c < cur) return false
        }
        return false
    }

    private fun verifyEd25519(rawPublicKeyB64: String, signatureB64: String, message: ByteArray): Boolean {
        return try {
            val rawKey = Base64.decode(rawPublicKeyB64, Base64.DEFAULT)
            if (rawKey.size != 32) return false
            val x509Key = ED25519_X509_PREFIX + rawKey
            val publicKey = KeyFactory.getInstance("Ed25519").generatePublic(X509EncodedKeySpec(x509Key))
            val sigBytes = Base64.decode(signatureB64, Base64.DEFAULT)
            val sig = Signature.getInstance("Ed25519")
            sig.initVerify(publicKey)
            sig.update(message)
            sig.verify(sigBytes)
        } catch (_: Exception) {
            false
        }
    }

    class GetStatus(private val context: Context) {
        fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val prefs = context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            return mapOf(
                "current_version" to (prefs.getString(KEY_VERSION, "0.0.0") ?: "0.0.0"),
                "pending" to pendingZip(context).exists(),
                "has_previous" to previousZip(context).exists(),
                "pending_version" to (prefs.getString(KEY_PENDING_VERSION, "") ?: "")
            )
        }
    }

    class Check(private val context: Context) {
        fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val endpoint = parameters["endpoint"] as? String ?: return mapOf("available" to false, "error" to "Invalid endpoint")
            val allowHttp = parameters["allow_insecure_http"] as? Boolean ?: false
            if (!isSecureUrl(endpoint, allowHttp)) {
                return mapOf("available" to false, "error" to "Insecure HTTP is not allowed")
            }

            val channel = parameters["channel"] as? String ?: "production"
            val prefs = context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            val currentVersion = (parameters["current_version"] as? String)?.takeIf { it.isNotEmpty() }
                ?: (prefs.getString(KEY_VERSION, "0.0.0") ?: "0.0.0")
            val token = parameters["token"] as? String ?: ""
            val (_, bakedFingerprint) = loadBakedConfig(context)
            val shellFingerprint = bakedFingerprint.ifEmpty { parameters["shell_fingerprint"] as? String ?: "" }

            val finalUrl = if (endpoint.endsWith(".json")) {
                endpoint
            } else {
                val sep = if (endpoint.contains("?")) "&" else "?"
                "${endpoint}${sep}channel=${URLEncoder.encode(channel, "UTF-8")}&version=${URLEncoder.encode(currentVersion, "UTF-8")}&shell_fingerprint=${URLEncoder.encode(shellFingerprint, "UTF-8")}"
            }

            return try {
                val conn = (URL(finalUrl).openConnection() as HttpURLConnection).apply {
                    requestMethod = "GET"
                    connectTimeout = 15000
                    readTimeout = 30000
                    if (token.isNotEmpty()) setRequestProperty("Authorization", "Bearer $token")
                }

                if (conn.responseCode !in 200..299) {
                    return mapOf("available" to false, "error" to "HTTP ${conn.responseCode}")
                }

                val body = conn.inputStream.bufferedReader().use { it.readText() }
                val json = JSONObject(body)
                val release = if (json.has("channels")) {
                    json.getJSONObject("channels").optJSONObject(channel)
                        ?: return mapOf("available" to false, "reason" to "No release for channel $channel")
                } else {
                    json
                }

                val version = release.optString("version", "")
                val url = release.optString("url", "")
                val sha256 = release.optString("sha256", "")
                val signature = release.optString("signature", "")
                if (version.isEmpty() || url.isEmpty() || sha256.isEmpty() || signature.isEmpty()) {
                    return mapOf("available" to false, "reason" to "Incomplete release metadata")
                }

                val releaseFingerprint = release.optString("shell_fingerprint", "")
                if (shellFingerprint.isNotEmpty() && releaseFingerprint.isNotEmpty() && shellFingerprint != releaseFingerprint) {
                    return mapOf("available" to false, "reason" to "Shell fingerprint mismatch")
                }

                if (!isNewerVersion(version, currentVersion)) {
                    return mapOf("available" to false, "reason" to "Already on latest version ($currentVersion)")
                }

                mapOf(
                    "available" to true,
                    "version" to version,
                    "url" to url,
                    "sha256" to sha256,
                    "size" to release.optLong("size", 0L),
                    "signature" to signature,
                    "shell_fingerprint" to releaseFingerprint,
                    "mandatory" to release.optBoolean("mandatory", false),
                    "release_notes" to release.optString("release_notes", "")
                )
            } catch (e: Exception) {
                mapOf("available" to false, "error" to (e.message ?: "Check failed"))
            }
        }
    }

    class Download(private val context: Context) {
        fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val urlStr = parameters["url"] as? String ?: return mapOf("success" to false, "error" to "Invalid URL")
            val allowHttp = parameters["allow_insecure_http"] as? Boolean ?: false
            if (!isSecureUrl(urlStr, allowHttp)) {
                return mapOf("success" to false, "error" to "Insecure HTTP download rejected")
            }

            val expectedSha256 = (parameters["sha256"] as? String ?: "").lowercase()
            val expectedSize = (parameters["size"] as? Number)?.toLong() ?: 0L
            val signatureB64 = parameters["signature"] as? String ?: ""
            val version = parameters["version"] as? String ?: ""
            val token = parameters["token"] as? String ?: ""

            val (publicKeyB64, bakedFingerprint) = loadBakedConfig(context)
            val shellFingerprint = bakedFingerprint.ifEmpty { parameters["shell_fingerprint"] as? String ?: "" }

            if (publicKeyB64.isEmpty()) {
                return mapOf("success" to false, "error" to "Missing baked-in Ed25519 public key")
            }

            return try {
                val conn = (URL(urlStr).openConnection() as HttpURLConnection).apply {
                    requestMethod = "GET"
                    connectTimeout = 15000
                    readTimeout = 120000
                    if (token.isNotEmpty()) setRequestProperty("Authorization", "Bearer $token")
                }

                if (conn.responseCode !in 200..299) {
                    return mapOf("success" to false, "error" to "HTTP ${conn.responseCode}")
                }

                val bytes = conn.inputStream.readBytes()
                if (expectedSize > 0 && bytes.size.toLong() != expectedSize) {
                    return mapOf("success" to false, "error" to "Size mismatch")
                }

                val actualSha256 = MessageDigest.getInstance("SHA-256")
                    .digest(bytes)
                    .joinToString("") { "%02x".format(it) }

                if (actualSha256 != expectedSha256) {
                    return mapOf("success" to false, "error" to "SHA-256 mismatch")
                }

                val canonical = "$actualSha256|$version|$shellFingerprint".toByteArray(Charsets.UTF_8)
                if (!verifyEd25519(publicKeyB64, signatureB64, canonical)) {
                    return mapOf("success" to false, "error" to "Ed25519 signature verification failed")
                }

                val pending = pendingZip(context)
                val previous = previousZip(context)
                if (pending.exists()) {
                    pending.copyTo(previous, overwrite = true)
                }

                val tempFile = File(otaDir(context), "download.tmp")
                tempFile.writeBytes(bytes)
                tempFile.renameTo(pending)

                context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
                    .edit()
                    .putString(KEY_PENDING_VERSION, version)
                    .apply()

                mapOf("success" to true, "version" to version, "size" to bytes.size)
            } catch (e: Exception) {
                mapOf("success" to false, "error" to (e.message ?: "Download failed"))
            }
        }
    }

    class Apply(private val context: Context) {
        fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val pending = pendingZip(context)
            if (!pending.exists()) {
                return mapOf("success" to false, "error" to "No pending update staged")
            }

            val prefs = context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            val version = (parameters["version"] as? String)?.takeIf { it.isNotEmpty() }
                ?: (prefs.getString(KEY_PENDING_VERSION, "") ?: "")

            val manifest = JSONObject().apply {
                put("version", version)
                put("queued_at", System.currentTimeMillis())
            }
            pendingManifest(context).writeText(manifest.toString())

            if (version.isNotEmpty()) {
                prefs.edit().putString(KEY_VERSION, version).apply()
            }

            return mapOf("success" to true, "version" to version, "apply_on_next_boot" to true)
        }
    }

    class Rollback(private val context: Context) {
        fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val previous = previousZip(context)
            if (!previous.exists()) {
                return mapOf("success" to false, "error" to "No previous update available")
            }
            previous.copyTo(pendingZip(context), overwrite = true)
            return mapOf("success" to true)
        }
    }

    class Prompt(private val context: Context) {
        fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val checkResult = Check(context).execute(parameters)
            if (checkResult["available"] != true) {
                return mapOf("prompted" to false, "reason" to (checkResult["reason"] ?: "No update"))
            }

            val version = checkResult["version"] as? String ?: ""
            Handler(Looper.getMainLooper()).post {
                AlertDialog.Builder(context)
                    .setTitle("Update Available")
                    .setMessage("Version $version is ready to install.")
                    .setNegativeButton("Later", null)
                    .setPositiveButton("Update") { _, _ ->
                        Thread {
                            val dlParams = parameters.toMutableMap()
                            dlParams["url"] = checkResult["url"] ?: ""
                            dlParams["sha256"] = checkResult["sha256"] ?: ""
                            dlParams["size"] = checkResult["size"] ?: 0
                            dlParams["signature"] = checkResult["signature"] ?: ""
                            dlParams["version"] = version
                            Download(context).execute(dlParams)
                            Apply(context).execute(mapOf("version" to version))
                        }.start()
                    }
                    .show()
            }

            return mapOf("prompted" to true, "version" to version)
        }
    }
}

