package app.lugha.tv.link

import android.content.Context
import android.net.nsd.NsdManager
import android.net.nsd.NsdServiceInfo

/** Advertises `_lugha._tcp` on the local network so senders find the TV without typing an address. */
class Discovery(context: Context) {
    private val nsd = context.getSystemService(NsdManager::class.java)
    private var listener: NsdManager.RegistrationListener? = null

    fun register(name: String, port: Int, fingerprint: String) {
        unregister()
        val info = NsdServiceInfo().apply {
            serviceName = name
            serviceType = Wire.SERVICE_TYPE
            setPort(port)
            setAttribute("v", "1")
            setAttribute("fp", fingerprint)
        }
        val l = object : NsdManager.RegistrationListener {
            override fun onServiceRegistered(info: NsdServiceInfo) {}
            override fun onRegistrationFailed(info: NsdServiceInfo, code: Int) {}
            override fun onServiceUnregistered(info: NsdServiceInfo) {}
            override fun onUnregistrationFailed(info: NsdServiceInfo, code: Int) {}
        }
        listener = l
        runCatching { nsd.registerService(info, NsdManager.PROTOCOL_DNS_SD, l) }
    }

    fun unregister() {
        listener?.let { runCatching { nsd.unregisterService(it) } }
        listener = null
    }
}
