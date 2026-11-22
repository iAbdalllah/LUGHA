package app.lugha.tv.link

/** JNI bindings to the Rust lugha-link crate (engine/lugha-link-jni). All results are JSON strings. */
object NativeLink {
    init { System.loadLibrary("lughalink") }

    external fun generateKeys(): String
    external fun newPairingCode(): String

    // Receiver (this TV)
    external fun pairingNew(code: String, secretHex: String): Long
    external fun pairingRespond(handle: Long, msgB64: String): String
    external fun pairingFinish(handle: Long, msgB64: String): String
    external fun pairingFree(handle: Long)
    external fun sessionAccept(secretHex: String, hs1B64: String, trustedJson: String): String

    // Sender (tests; the phone app in Phase 4)
    external fun senderPairStart(code: String, secretHex: String): String
    external fun senderPairFinish(handle: Long, msgB64: String): String
    external fun sessionInitiate(secretHex: String, peerB64: String): String
    external fun sessionComplete(handle: Long, msgB64: String): String

    // Both
    external fun encrypt(handle: Long, text: String): String
    external fun decrypt(handle: Long, msgB64: String): String
    external fun sessionFree(handle: Long)
}
