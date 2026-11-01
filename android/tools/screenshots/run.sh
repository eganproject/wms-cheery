#!/usr/bin/env bash
# Ambil screenshot aplikasi di emulator Android (dijalankan GitHub Actions,
# lihat .github/workflows/android-screenshots.yml). Aplikasi bicara ke server
# tiruan mock_server.py lewat 10.0.2.2:8000.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
OUT="${OUT:-$PWD/screenshots}"
PKG=id.cheerychic.wmsscan
APK="$HERE/../../app/build/outputs/apk/debug/app-debug.apk"
mkdir -p "$OUT"

# Keluaran server dialihkan ke berkas dan servernya dimatikan di akhir:
# proses latar yang masih memegang stdout membuat langkah CI tidak pernah selesai.
python3 "$HERE/mock_server.py" > /tmp/mock_server.log 2>&1 < /dev/null &
MOCK_PID=$!
# Catat crash aplikasi (bila ada) supaya bisa dibaca dari branch screenshot.
crashlog() { adb logcat -d -b crash > "$OUT/crash.txt" 2>/dev/null || true; adb logcat -d -s AndroidRuntime:E > "$OUT/runtime.txt" 2>/dev/null || true; }
trap 'crashlog; kill $MOCK_PID 2>/dev/null || true' EXIT
sleep 2

adb wait-for-device
adb shell 'while [ "$(getprop sys.boot_completed)" != "1" ]; do sleep 1; done'
adb install -r "$APK"
# Kamera menyala otomatis di layar scan; izinkan supaya dialog izin tidak menutupi.
adb shell pm grant $PKG android.permission.CAMERA || true

# Emulator di CI kadang menampilkan dialog "Pixel Launcher isn't responding".
adb shell settings put global hide_error_dialogs 1 || true
sleep 15

# Tanpa keyboard layar supaya tidak menutupi tampilan; "adb input" tetap jalan.
for ime in $(adb shell ime list -s | tr -d '\r'); do adb shell ime disable "$ime" || true; done

# Status bar rapi: jam 09:30, baterai penuh.
adb shell settings put global sysui_demo_allowed 1
adb shell am broadcast -a com.android.systemui.demo -e command enter >/dev/null
adb shell am broadcast -a com.android.systemui.demo -e command clock -e hhmm 0930 >/dev/null
adb shell am broadcast -a com.android.systemui.demo -e command battery -e level 100 -e plugged false >/dev/null
adb shell am broadcast -a com.android.systemui.demo -e command network -e wifi show -e level 4 >/dev/null
adb shell am broadcast -a com.android.systemui.demo -e command notifications -e visible false >/dev/null

dismiss() { adb shell am broadcast -a android.intent.action.CLOSE_SYSTEM_DIALOGS >/dev/null 2>&1 || true; }
shot() { sleep "${2:-2}"; dismiss; sleep 0.5; adb exec-out screencap -p > "$OUT/$1.png"; echo "shot $1"; }

prefs() {
  printf '%s' "$1" > /tmp/prefs.xml
  adb push /tmp/prefs.xml /data/local/tmp/wms-scan.xml >/dev/null
  adb shell "run-as $PKG sh -c 'mkdir -p shared_prefs && cp /data/local/tmp/wms-scan.xml shared_prefs/wms-scan.xml'"
}

launch() {
  adb shell am force-stop $PKG
  adb shell am start -n $PKG/.MainActivity >/dev/null
}

# Ketuk elemen yang teksnya mengandung $1 (yang paling atas di layar).
tap() {
  dismiss
  adb shell uiautomator dump /sdcard/ui.xml >/dev/null
  adb pull /sdcard/ui.xml /tmp/ui.xml >/dev/null
  read -r x y < <(python3 - "$1" <<'PY'
import re, sys, xml.etree.ElementTree as ET
needle = sys.argv[1]
best = None
for n in ET.parse('/tmp/ui.xml').iter('node'):
    text = (n.get('text') or '') + ' ' + (n.get('content-desc') or '')
    if needle in text:
        x1, y1, x2, y2 = map(int, re.findall(r'\d+', n.get('bounds')))
        if best is None or y1 < best[1]:
            best = ((x1 + x2) // 2, y1, (y1 + y2) // 2)
if best is None:
    sys.exit('teks tidak ketemu: ' + needle)
print(best[0], best[2])
PY
)
  adb shell input tap "$x" "$y"
}

scan() { adb shell input text "$1"; adb shell input keyevent 66; sleep 1.5; }

SERVER='http://10.0.2.2:8000'
USER_JSON='{&quot;id&quot;:1,&quot;name&quot;:&quot;Rina&quot;,&quot;email&quot;:&quot;rina@cheerychic.id&quot;,&quot;role&quot;:&quot;Staff Gudang&quot;,&quot;can&quot;:{&quot;kemas&quot;:true,&quot;serah_terima&quot;:true,&quot;pesanan&quot;:true}}'

# 1. Login
adb shell am start -n $PKG/.MainActivity >/dev/null; sleep 3
prefs "<?xml version='1.0' encoding='utf-8' standalone='yes' ?><map><string name=\"server\">https://wms.cheerychic.id</string><string name=\"email\">rina@cheerychic.id</string></map>"
launch; shot 01-login 9

# 2. Beranda (sudah login)
prefs "<?xml version='1.0' encoding='utf-8' standalone='yes' ?><map><string name=\"server\">$SERVER</string><string name=\"email\">rina@cheerychic.id</string><string name=\"token\">demo</string><string name=\"user\">$USER_JSON</string></map>"
launch; shot 02-beranda 5

# 3. Scan Kemas
tap "Scan Kemas"; shot 03-kemas-menunggu-resi 3
scan SPXID04918822731; shot 04-kemas-resi 1
crashlog
scan 8991234500035; shot 05-kemas-sebagian 1
scan 8991234500035
# Layar hijau "Paket lengkap" hanya tampil ~2 detik: ambil cepat.
adb shell input text CC-HJB-PASMINA-CRM; adb shell input keyevent 66; shot 06-kemas-lengkap 0.7
sleep 3; shot 06b-kemas-siap-resi-berikutnya 0
launch; sleep 4

# 4. Serah Terima
tap "Serah Terima"; sleep 3
scan SPXID04918822705; scan JX3820194488; scan SPXID04918822706; shot 07-serah-terima 1
launch; sleep 4

# 5. Daftar pesanan
tap "Pesanan"; shot 08-pesanan-belum 4
tap "Prioritas"; shot 08b-pesanan-prioritas 3
tap "Dikemas"; shot 09-pesanan-sudah 3
tap "Dikirim"; shot 10-pesanan-dikirim 3
tap "Dikemas"; sleep 3; tap "SPXID04918822705"; shot 11-rincian-resi 3

ls -la "$OUT"
