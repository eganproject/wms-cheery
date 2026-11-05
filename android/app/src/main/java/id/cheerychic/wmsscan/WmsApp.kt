package id.cheerychic.wmsscan

import android.app.Application
import id.cheerychic.wmsscan.data.Session
import id.cheerychic.wmsscan.data.Updater

class WmsApp : Application() {
    override fun onCreate() {
        super.onCreate()
        Session.init(this)
        Updater.init(this)
    }
}
