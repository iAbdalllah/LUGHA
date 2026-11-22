package app.lugha.tv.ui

import android.os.Bundle
import android.view.WindowManager
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import app.lugha.tv.link.AppVisibility
import app.lugha.tv.link.LinkService

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        LinkService.start(this)
        setContent { LughaTv() }
    }

    override fun onResume() {
        super.onResume()
        AppVisibility.foreground = true
    }

    override fun onPause() {
        AppVisibility.foreground = false
        super.onPause()
    }
}
