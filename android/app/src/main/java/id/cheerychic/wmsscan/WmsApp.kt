package id.cheerychic.wmsscan

import android.app.Application
import id.cheerychic.wmsscan.data.Session

class WmsApp : Application() {
    override fun onCreate() {
        super.onCreate()
        Session.init(this)
    }
}
