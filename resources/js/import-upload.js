/**
 * Unggah berkas import tanpa memuat ulang halaman.
 *
 * Berkas dikirim lewat XMLHttpRequest (bukan fetch) supaya kemajuan unggahan
 * bisa ditampilkan. Server menjawab JSON: alamat tujuan bila berhasil, atau
 * pesan kesalahan (422) yang ditampilkan di bawah kotak berkas. Selama proses
 * berjalan, tombol dikunci dan layar ditutup lapisan memuat sehingga berkas
 * yang sama tidak bisa terkirim dua kali.
 */
export default function importUpload(config = {}) {
    const maxBytes = (config.maxMb ?? 20) * 1024 * 1024;
    const extensions = config.extensions ?? ['xlsx', 'xls', 'csv', 'txt'];

    return {
        name: '',
        drag: false,
        busy: false,
        phase: 'upload', // upload | process
        percent: 0,
        error: '',

        init() {
            // Peringatkan bila tab ditutup saat import masih berjalan.
            this.guard = (event) => {
                if (!this.busy) return;
                event.preventDefault();
                event.returnValue = '';
            };
            window.addEventListener('beforeunload', this.guard);
        },

        destroy() {
            window.removeEventListener('beforeunload', this.guard);
        },

        pick(files) {
            if (this.busy) return;
            this.error = '';
            this.name = files?.[0]?.name ?? '';
        },

        drop(event) {
            this.drag = false;
            if (this.busy || !event.dataTransfer?.files?.length) return;

            // Masukkan berkas yang ditarik ke input agar ikut terkirim.
            this.$refs.file.files = event.dataTransfer.files;
            this.pick(event.dataTransfer.files);
        },

        /** Pemeriksaan ringan sebelum mengunggah; server tetap memeriksa ulang. */
        check(file) {
            if (!file) return 'Pilih berkas eksport TikTok Shop lebih dulu.';

            const extension = file.name.split('.').pop()?.toLowerCase();
            if (!extensions.includes(extension)) {
                return 'Berkas harus berakhiran .xlsx, .xls, atau .csv — hasil eksport langsung dari TikTok Shop.';
            }

            if (file.size > maxBytes) return `Ukuran berkas melebihi ${config.maxMb ?? 20} MB.`;

            return '';
        },

        submit() {
            if (this.busy) return;

            const form = this.$el.closest('form') ?? this.$el;
            const file = this.$refs.file.files[0];

            this.error = this.check(file);
            if (this.error) return;

            // Ambil isi form sebelum dikunci: input yang dinonaktifkan tidak ikut terkirim.
            const body = new FormData(form);

            this.busy = true;
            this.phase = 'upload';
            this.percent = 0;

            const xhr = new XMLHttpRequest();
            xhr.open('POST', form.action);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

            xhr.upload.onprogress = (event) => {
                if (!event.lengthComputable) return;
                this.percent = Math.round((event.loaded / event.total) * 100);
            };

            // Unggahan selesai, server mulai membaca dan mencocokkan isinya.
            xhr.upload.onload = () => {
                this.percent = 100;
                this.phase = 'process';
            };

            xhr.onload = () => this.finish(xhr);
            xhr.onerror = () => this.fail('Koneksi terputus saat mengunggah. Periksa jaringan lalu coba lagi.');

            xhr.send(body);
        },

        finish(xhr) {
            let data = null;
            try {
                data = JSON.parse(xhr.responseText);
            } catch {
                // Bukan JSON, ditangani di bawah berdasarkan status.
            }

            if (xhr.status >= 200 && xhr.status < 300 && data?.redirect) {
                this.busy = false;
                this.goTo(data.redirect);
                return;
            }

            // Sesi habis atau token kedaluwarsa: muat ulang agar bisa masuk lagi.
            if (xhr.status === 401 || xhr.status === 419) {
                this.busy = false;
                window.location.reload();
                return;
            }

            if (xhr.status === 422) {
                const messages = data?.errors ? Object.values(data.errors).flat() : [];
                this.fail(messages[0] ?? data?.message ?? 'Berkas tidak dapat diimport.');
                return;
            }

            if (xhr.status === 413) {
                this.fail('Berkas terlalu besar untuk diterima server. Batas unggah di pengaturan PHP perlu dinaikkan.');
                return;
            }

            if (xhr.status === 403) {
                this.fail('Akun Anda tidak punya izin untuk mengimport resi.');
                return;
            }

            this.fail('Terjadi kesalahan di server saat memproses berkas. Cek riwayat import sebelum mengunggah ulang.');
        },

        fail(message) {
            this.busy = false;
            this.error = message;
        },

        goTo(url) {
            if (typeof window.wmsNavigate === 'function') window.wmsNavigate(url);
            else window.location.href = url;
        },
    };
}
