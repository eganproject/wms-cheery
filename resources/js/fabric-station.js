/**
 * Stasiun Scan Kain.
 *
 *   scan QR roll → kartu roll terbuka (SKU kain, sisa) → pilih barang yang
 *   dibuat dari kain ini → ketik jumlah / Ambil semua → Enter → siap scan
 *   roll berikutnya
 *
 * Barang yang memakai kain roll itu (Pemakaian Kain per Barang) langsung
 * jadi pilihan; barang lain dicari lewat kotak cari. Barang terakhir
 * diingat di perangkat dan otomatis terpilih bila memakai kain yang sama.
 *
 * Mode "cek": jumlah yang diketik adalah sisa hasil ukur ulang roll; selisih
 * dengan sisa tercatat disimpan sebagai koreksi.
 *
 * Kolom jumlah sengaja bertipe teks: scanner genggam mengetik seperti papan
 * ketik, jadi bila operator langsung memindai roll lain selagi kolom jumlah
 * terfokus, isinya dikenali sebagai kode roll baru alih-alih ditolak.
 */
import { announce, timestamp } from './feedback';
import { cameraState } from './scan-state';

const NUMBER = /^\d+(?:[.,]\d{1,3})?$/;
const PRODUCT_KEY = 'fabric-station-product';

export default function fabricStation(config) {
    return {
        urls: config.urls,
        mode: config.mode,
        canIssue: config.canIssue,
        canAdjust: config.canAdjust,
        recent: config.recent ?? [],

        code: '',
        quantity: '',
        note: '',

        /** Barang tujuan kain keluar. */
        product: null,
        productQuery: '',
        productOptions: [],
        productOpen: false,
        productLoading: false,
        productTimer: null,
        lastProductId: null,
        busy: false,
        feedback: null,

        /** Roll yang sedang dibuka; null berarti menunggu scan. */
        roll: null,

        init() {
            try {
                this.lastProductId = Number(localStorage.getItem(PRODUCT_KEY)) || null;
            } catch (error) {
                this.lastProductId = null;
            }

            this.$watch('mode', () => this.cancel());
            this.focusCode();
        },

        get isCheck() {
            return this.mode === 'cek';
        },

        get title() {
            if (! this.roll) return this.isCheck ? 'Scan QR roll yang dicek' : 'Scan QR roll kain';

            return this.roll.code;
        },

        get quantityLabel() {
            return this.isCheck ? 'Sisa hasil ukur' : 'Jumlah keluar';
        },

        /* ------------------------------------------------------- alur ---- */

        async submit() {
            if (this.busy) return;

            const raw = this.code.trim();

            if (! raw) return;

            // Kartu terbuka dan yang diketik angka: itu jumlahnya.
            if (this.roll && NUMBER.test(raw)) {
                this.quantity = raw;
                this.code = '';

                await this.save();

                return;
            }

            await this.lookup(raw);
        },

        async lookup(code) {
            this.busy = true;
            this.feedback = null;

            try {
                const payload = await this.post(this.urls.lookup, { code });

                this.roll = payload.roll;
                this.code = '';
                this.quantity = '';
                this.preselectProduct();
                this.report(payload.roll.empty ? 'error' : 'success', payload.message, payload.roll.empty ? 'rejected' : 'resi');

                if (payload.roll.empty && ! this.isCheck) {
                    this.roll = null;
                }
            } catch (error) {
                this.roll = null;
                this.report('error', error.message, 'error');
            } finally {
                this.busy = false;
                this.$nextTick(() => (this.roll ? this.focusQuantity() : this.focusCode()));
            }
        },

        /** Enter di kolom jumlah. Bukan angka berarti kode roll baru. */
        async quantityEnter() {
            const raw = String(this.quantity ?? '').trim();

            if (raw !== '' && ! NUMBER.test(raw)) {
                this.quantity = '';
                await this.lookup(raw);

                return;
            }

            await this.save();
        },

        async takeAll() {
            await this.save(true);
        },

        async save(all = false) {
            if (this.busy || ! this.roll) return;

            const raw = String(this.quantity ?? '').trim().replace(',', '.');

            if (! this.isCheck && ! this.product) {
                this.report('error', 'Pilih barang yang dibuat dari kain ini.', 'error');
                this.$nextTick(() => this.$refs.productSearch?.focus());

                return;
            }

            if (! all && (raw === '' || Number.isNaN(Number(raw)))) {
                this.report('error', this.isCheck ? 'Isi sisa hasil ukur roll ini.' : 'Isi jumlah kain keluar, atau tekan Ambil Semua.', 'error');
                this.focusQuantity();

                return;
            }

            this.busy = true;

            try {
                const payload = this.isCheck
                    ? await this.post(this.urls.adjust, { code: this.roll.code, actual: Number(raw), note: this.note || null })
                    : await this.post(this.urls.issue, {
                        code: this.roll.code,
                        all,
                        quantity: all ? null : Number(raw),
                        product_id: this.product.id,
                        note: this.note || null,
                    });

                this.report('success', payload.message, this.isCheck ? 'item' : 'complete');

                if (payload.recent) {
                    this.recent = payload.recent;
                }

                this.roll = null;
                this.product = null;
                this.quantity = '';
                this.note = '';
            } catch (error) {
                this.report('error', error.message, 'error');
            } finally {
                this.busy = false;
                this.$nextTick(() => (this.roll ? this.focusQuantity() : this.focusCode()));
            }
        },

        /* ----------------------------------------------------- barang ---- */

        /** Satu-satunya barang pemakai kain, atau barang terakhir bila cocok. */
        preselectProduct() {
            const options = this.roll?.products ?? [];

            this.productQuery = '';
            this.productOpen = false;
            this.productOptions = options;
            this.product = options.length === 1
                ? options[0]
                : (options.find((item) => item.id === this.lastProductId) ?? null);
        },

        openProducts() {
            this.productOpen = true;

            if (! this.productQuery) this.productOptions = this.roll?.products ?? [];
            if (this.productOptions.length === 0) this.searchProducts();
        },

        searchProducts() {
            clearTimeout(this.productTimer);
            this.productOpen = true;
            this.productTimer = setTimeout(async () => {
                const term = this.productQuery.trim();

                if (! term && (this.roll?.products ?? []).length) {
                    this.productOptions = this.roll.products;

                    return;
                }

                this.productLoading = true;

                try {
                    const params = new URLSearchParams({ q: term, code: this.roll?.code ?? '' });
                    const response = await fetch(`${this.urls.products}?${params}`, {
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    });
                    const payload = await response.json();

                    this.productOptions = payload.products ?? [];
                } catch (error) {
                    this.productOptions = [];
                } finally {
                    this.productLoading = false;
                }
            }, 200);
        },

        pickProduct(item) {
            this.product = item;
            this.productOpen = false;
            this.productQuery = '';
            this.lastProductId = item.id;

            try {
                localStorage.setItem(PRODUCT_KEY, String(item.id));
            } catch (error) {
                // Penyimpanan peramban tidak tersedia; barang tidak diingat.
            }

            this.$nextTick(() => this.focusQuantity());
        },

        isUsage(item) {
            return (this.roll?.products ?? []).some((usage) => usage.id === item.id);
        },

        cancel() {
            this.roll = null;
            this.product = null;
            this.quantity = '';
            this.note = '';
            this.feedback = null;
            this.$nextTick(() => this.focusCode());
        },

        /* ------------------------------------------------------ bantu ---- */

        report(type, message, sound) {
            this.feedback = { type, message, time: timestamp() };

            if (sound) announce(sound);
        },

        focusCode() {
            if (cameraState.open) return;

            this.$refs.code?.focus();
        },

        focusQuantity() {
            if (cameraState.open) return;

            this.$refs.quantity?.focus();
            this.$refs.quantity?.select();
        },

        async post(url, body) {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify(body),
            });

            const payload = await response.json().catch(() => ({}));

            if (! response.ok) {
                const errors = payload.errors ?? {};
                const first = Object.values(errors)[0]?.[0];

                throw new Error(first ?? payload.message ?? 'Gagal diproses.');
            }

            return payload;
        },
    };
}
