/**
 * Penyusun varian di formulir produk induk.
 *
 * Isi nilai tiap atribut (mis. Warna: Merah, Biru; Ukuran: S, M, L), lalu
 * semua kombinasinya dibuat sebagai baris varian dengan SKU usulan. Setiap
 * baris tetap bisa diubah atau dihapus sebelum disimpan, karena SKU-nya harus
 * sama persis dengan Seller SKU di marketplace.
 */
let uid = 0;

export default function variantBuilder(initial = {}) {
    return {
        option1Name: initial.option1Name ?? 'Warna',
        option2Name: initial.option2Name ?? '',
        values1: initial.values1 ?? '',
        values2: initial.values2 ?? '',
        skuPrefix: initial.skuPrefix ?? '',
        minStock: initial.minStock ?? 0,
        rows: (initial.rows ?? []).map((row) => ({ ...row, uid: ++uid })),

        get hasSecond() {
            return this.option2Name.trim() !== '';
        },

        split(text) {
            const seen = new Set();

            return text.split(/[,;\n]/)
                .map((value) => value.trim())
                .filter((value) => value !== '' && ! seen.has(value.toLowerCase()) && seen.add(value.toLowerCase()));
        },

        code(value) {
            return value.normalize('NFKD').replace(/[^A-Za-z0-9]+/g, '').toUpperCase().slice(0, 12);
        },

        key(option1, option2) {
            return `${option1}`.trim().toLowerCase() + '|' + (this.hasSecond ? `${option2 ?? ''}`.trim().toLowerCase() : '');
        },

        /**
         * Bentuk semua kombinasi. Baris yang sudah ada dipertahankan apa
         * adanya supaya SKU dan barcode yang sudah diketik tidak hilang.
         */
        generate() {
            const first = this.split(this.values1);
            const second = this.hasSecond ? this.split(this.values2) : [null];

            if (first.length === 0 || second.length === 0) {
                return;
            }

            const kept = new Map(this.rows.map((row) => [this.key(row.option1, row.option2), row]));
            const prefix = this.code(this.skuPrefix);

            this.rows = first.flatMap((option1) => second.map((option2) => {
                const existing = kept.get(this.key(option1, option2));

                if (existing) {
                    return existing;
                }

                const parts = [prefix, this.code(option1), option2 ? this.code(option2) : null].filter(Boolean);

                return {
                    uid: ++uid,
                    option1,
                    option2: option2 ?? '',
                    sku: parts.join('-'),
                    barcode: '',
                    location: '',
                    min_stock: this.minStock,
                };
            }));
        },

        addRow() {
            this.rows.push({ uid: ++uid, option1: '', option2: '', sku: '', barcode: '', location: '', min_stock: this.minStock });
        },

        removeRow(index) {
            this.rows.splice(index, 1);
        },
    };
}
