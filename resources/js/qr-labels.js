/**
 * Halaman Cetak Label QR: daftar barang yang akan dicetak beserta jumlah
 * labelnya. Barang ditambah satu per satu, atau seluruh varian satu produk
 * sekaligus; barang yang sudah ada di daftar tidak digandakan.
 */
export default function qrLabels(config) {
    return {
        items: config.items ?? [],
        perPage: config.perPage ?? {},
        layout: config.layout ?? '',
        stacked: config.stacked ?? {},

        /* Posisi tulisan mengikuti bawaan ukuran label (thermal: di bawah,
           A4: di kanan) sampai pengguna memilihnya sendiri. */
        position: '',
        positionTouched: false,

        init() {
            this.position = this.defaultPosition();
            this.$watch('layout', () => {
                if (! this.positionTouched) this.position = this.defaultPosition();
            });
        },

        defaultPosition() {
            return this.stacked[this.layout] ? 'below' : 'right';
        },
        maxLabels: config.maxLabels ?? 3000,
        maxCopies: config.maxCopies ?? 500,
        bulkCopies: 1,
        skip: 0,

        get total() {
            return this.items.reduce((sum, item) => sum + Math.max(0, parseInt(item.copies, 10) || 0), 0);
        },

        get sheet() {
            return (this.perPage[this.layout] ?? 1) > 1;
        },

        get pages() {
            const per = this.perPage[this.layout] ?? 1;
            const skip = this.sheet ? Math.min(parseInt(this.skip, 10) || 0, per - 1) : 0;

            return this.total > 0 ? Math.ceil((this.total + skip) / per) : 0;
        },

        get tooMany() {
            return this.total > this.maxLabels;
        },

        has(id) {
            return this.items.some((item) => item.id === id);
        },

        /** Dari <option> pilihan barang: data-* membawa SKU dan nama. */
        addFromSelect(select) {
            const option = select.selectedOptions[0];
            if (! option || ! option.value) return;

            if (option.dataset.variants) {
                JSON.parse(option.dataset.variants).forEach((item) => this.add(item));
            } else {
                this.add({
                    id: parseInt(option.value, 10),
                    sku: option.dataset.sku,
                    name: option.dataset.name,
                    variant: option.dataset.variant ?? '',
                });
            }

            // Kosongkan lagi supaya barang berikutnya bisa langsung dicari.
            select.value = '';
            select.tomselect?.clear(true);
        },

        add(item) {
            if (this.has(item.id)) return;

            this.items.push({ ...item, copies: Math.max(1, parseInt(this.bulkCopies, 10) || 1) });
        },

        remove(index) {
            this.items.splice(index, 1);
        },

        applyCopies() {
            const copies = Math.min(this.maxCopies, Math.max(1, parseInt(this.bulkCopies, 10) || 1));
            this.items.forEach((item) => (item.copies = copies));
        },

        clear() {
            this.items = [];
        },
    };
}
