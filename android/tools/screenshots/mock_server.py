"""Server tiruan /api/mobile untuk mengambil screenshot aplikasi di emulator.

Datanya contoh, bentuk jawabannya sama dengan API Laravel (lihat
app/Http/Controllers/Api/Mobile dan tests/Feature/Api/MobileAppApiTest.php).
"""
import json
import re
import struct
import zlib
from http.server import BaseHTTPRequestHandler, HTTPServer

USER = {"id": 1, "name": "Rina", "email": "rina@cheerychic.id", "role": "Staff Gudang",
        "can": {"kemas": True, "serah_terima": True, "pesanan": True}}


def png(rgb, size=96):
    """PNG polos satu warna sebagai pengganti foto produk."""
    raw = b"".join(b"\x00" + bytes(rgb) * size for _ in range(size))

    def chunk(kind, data):
        return struct.pack(">I", len(data)) + kind + data + struct.pack(">I", zlib.crc32(kind + data) & 0xFFFFFFFF)

    return (b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", size, size, 8, 2, 0, 0, 0))
            + chunk(b"IDAT", zlib.compress(raw)) + chunk(b"IEND", b""))


IMAGES = {"maroon": png((128, 28, 46)), "cream": png((236, 224, 200))}

ITEMS = [
    {"id": 11, "sku": "CC-DRS-MAWAR-M", "name": "Dress Mawar Brokat - Maroon M", "barcode": "8991234500035",
     "image_url": "/img/maroon.png", "unit": "pcs", "stock": 24, "quantity": 2},
    {"id": 12, "sku": "CC-HJB-PASMINA-CRM", "name": "Pashmina Ceruti Cream", "barcode": "8991234500042",
     "image_url": "/img/cream.png", "unit": "pcs", "stock": 51, "quantity": 1},
]
scanned = {11: 0, 12: 0}
shipped = []


def order(i, resi, stage, **extra):
    base = {
        "id": i, "tracking_number": resi, "order_number": f"5781{i:08d}", "marketplace": "TikTok Shop",
        "store_name": "CheeryChic Official", "buyer_name": ["Siti A.", "Dewi K.", "Ayu P.", "Nina R.", "Putri L."][i % 5],
        "courier": ["J&T Express", "SPX Express", "JNE"][i % 3], "stage": stage,
        "stage_label": {"belum-qc": "Belum Dikemas", "serah-terima": "Sudah Dikemas", "dikirim": "Dikirim"}[stage],
        "stage_detail": {"belum-qc": "Belum discan sama sekali", "serah-terima": "QC selesai, menunggu diproses",
                         "dikirim": "Dikirim 01 Nov 2026 09:12"}[stage],
        "ready_label": "", "kind": "Normal", "is_priority": False, "ship_by": None, "deadline_label": None,
        "deadline_tone": None, "total_quantity": 3, "scanned_quantity": 0, "shipped_at": None,
        "items": [{"sku": "CC-DRS-MAWAR-M", "name": "Dress Mawar Brokat - Maroon M", "quantity": 2,
                   "image_url": "/img/maroon.png"},
                  {"sku": "CC-HJB-PASMINA-CRM", "name": "Pashmina Ceruti Cream", "quantity": 1,
                   "image_url": "/img/cream.png"}],
    }
    base.update(extra)
    return base


BELUM = [
    order(1, "JX3820194470", "belum-qc", ready_label="Siap dikemas", is_priority=True, deadline_label="Hari ini", deadline_tone="danger"),
    order(2, "SPXID04918822731", "belum-qc", ready_label="Siap dikemas", deadline_label="Besok", deadline_tone="warning"),
    order(3, "JX3820194488", "belum-qc", ready_label="Menunggu stok (CC-DRS-MAWAR-L kurang 3)", kind="PO 7 hari", deadline_label="Sisa 5 hari", deadline_tone="neutral"),
    order(4, "CM8827361920", "belum-qc", ready_label="Siap dikemas", stage_detail="Barang 1/3 unit discan", scanned_quantity=1, deadline_label="Besok", deadline_tone="warning"),
]
SUDAH = [order(i, f"SPXID0491882{2700 + i}", "serah-terima", scanned_quantity=3) for i in range(5, 9)]
DIKIRIM = [order(i, f"JX38201{94400 + i}", "dikirim", scanned_quantity=3, shipped_at="2026-11-01T09:12:00+07:00") for i in range(9, 14)]


class Handler(BaseHTTPRequestHandler):
    def send(self, status, payload):
        body = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def body(self):
        length = int(self.headers.get("Content-Length") or 0)
        return json.loads(self.rfile.read(length) or b"{}")

    def progress(self):
        total = sum(i["quantity"] for i in ITEMS)
        done = sum(scanned[i["id"]] for i in ITEMS)
        return {"resi_verified": True, "total": total, "scanned": done, "percentage": round(done * 100 / total),
                "ready": done >= total,
                "items": [{"id": i["id"], "scanned": scanned[i["id"]], "quantity": i["quantity"],
                           "completed": scanned[i["id"]] >= i["quantity"]} for i in ITEMS]}

    def do_GET(self):
        path = self.path.split("?")[0]
        query = self.path.split("?")[1] if "?" in self.path else ""
        m = re.match(r"/img/(\w+)\.png$", path)
        if m and m.group(1) in IMAGES:
            body = IMAGES[m.group(1)]
            self.send_response(200)
            self.send_header("Content-Type", "image/png")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return
        if path == "/api/mobile/me":
            return self.send(200, {"user": USER})
        if path == "/api/mobile/summary":
            return self.send(200, {"date": "2026-11-01", "belum_dikemas": 37, "siap_dikemas": 29,
                                   "menunggu_stok": 8, "prioritas": 3, "sudah_dikemas": 12, "dikirim": 58,
                                   "batas_hari_ini_terbuka": 49, "lewat_batas": 12, "dikirim_sejam_terakhir": 8,
                                   "antrean_per_kurir": [{"courier": "J&T Express", "total": 6},
                                                         {"courier": "SPX Express", "total": 3},
                                                         {"courier": "Tanpa kurir", "total": 2},
                                                         {"courier": "JNE", "total": 1}]})
        if path == "/api/mobile/orders":
            stage = re.search(r"stage=([a-z-]+)", query)
            stage = stage.group(1) if stage else "belum-qc"
            data = {"belum-qc": BELUM, "serah-terima": SUDAH, "dikirim": DIKIRIM}[stage]
            total = {"belum-qc": 37, "serah-terima": 12, "dikirim": 58}[stage]
            return self.send(200, {"stage": stage, "date": "2026-11-01", "data": data,
                                   "meta": {"current_page": 1, "last_page": 1, "total": total}})
        m = re.match(r"/api/mobile/orders/(\d+)$", path)
        if m:
            o = dict(order(5, "SPXID04918822705", "serah-terima", scanned_quantity=3))
            o.update({"buyer_note": "Tolong dibungkus rapi ya kak", "outbound_code": "OUT-202611-0142",
                      "packed_items": [{"sku": i["sku"], "name": i["name"], "barcode": i["barcode"], "image_url": i["image_url"],
                                        "quantity": i["quantity"], "scanned": i["quantity"]} for i in ITEMS]})
            return self.send(200, {"order": o})
        self.send(404, {"message": "Not found"})

    def do_POST(self):
        path = self.path.split("?")[0]
        data = self.body()
        code = str(data.get("code", "")).strip()
        if path == "/api/mobile/login":
            return self.send(200, {"token": "demo", "user": USER})
        if path == "/api/mobile/kemas/resi":
            for k in scanned:
                scanned[k] = 0
            return self.send(200, {
                "outbound": {"id": 142, "code": "OUT-202611-0142", "tracking_number": code,
                             "marketplace": "TikTok Shop", "recipient": "Siti A.", "order_number": "578100000001"},
                "items": ITEMS, "bundles": [], "progress": self.progress()})
        if re.match(r"/api/mobile/kemas/\d+/item$", path):
            item = next((i for i in ITEMS if code in (i["barcode"], i["sku"])), None)
            if not item:
                return self.send(422, {"message": "x", "errors": {"code": [f"Kode {code} tidak dikenali sebagai barcode maupun SKU barang mana pun."]}})
            scanned[item["id"]] = min(item["quantity"], scanned[item["id"]] + 1)
            return self.send(200, {"message": f"{item['sku']} · {scanned[item['id']]}/{item['quantity']} pcs",
                                   "sku": item["sku"], "item_id": item["id"],
                                   "completed": scanned[item["id"]] >= item["quantity"], "progress": self.progress()})
        if path == "/api/mobile/serah-terima/scan":
            if code.startswith("JX3820194488"):
                return self.send(422, {"message": "x", "errors": {"code": ["Belum selesai QC · sisa 2 unit"]}})
            shipped.append(code)
            return self.send(200, {"outbound": {"id": 100 + len(shipped), "code": f"OUT-202611-01{40 + len(shipped)}",
                                                "tracking_number": code, "marketplace": "TikTok Shop",
                                                "recipient": "Dewi K.", "units": 3},
                                   "shipped": True, "message": "Dikirim · 3 unit", "remaining": 12 - len(shipped)})
        self.send(404, {"message": "Not found"})

    def log_message(self, *args):
        pass


HTTPServer(("0.0.0.0", 8000), Handler).serve_forever()
