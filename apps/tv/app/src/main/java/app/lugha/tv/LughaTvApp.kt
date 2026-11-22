package app.lugha.tv

import android.app.Application
import app.lugha.tv.link.LinkHub

class LughaTvApp : Application() {
    override fun onCreate() {
        super.onCreate()
        LinkHub.init(this)
    }
}
