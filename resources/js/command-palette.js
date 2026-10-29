/**
 * Pencarian cepat bergaya awesomebar ERPNext.
 *
 * Daftarnya dikirim server sekali saat halaman dimuat (sudah disaring menurut
 * izin pengguna), lalu disaring di peramban: setiap kata yang diketik harus
 * muncul di judul, kelompok, atau kata kuncinya.
 */
export default function commandPalette({ items = [], searches = [] } = {}) {
    return {
        open: false,
        query: '',
        active: 0,
        items,
        searches,

        get results() {
            const words = this.query.toLowerCase().trim().split(/\s+/).filter(Boolean);

            if (words.length === 0) {
                return this.items.filter((item) => item.group !== 'Buat Baru').slice(0, 30);
            }

            const scored = this.items
                .map((item) => {
                    const label = item.label.toLowerCase();
                    const haystack = `${label} ${item.group.toLowerCase()} ${item.keywords ?? ''}`;

                    if (!words.every((word) => haystack.includes(word))) return null;

                    // Judul yang diawali kata pertama naik ke atas.
                    const score = (label.startsWith(words[0]) ? 0 : label.includes(words[0]) ? 1 : 2);

                    return { item, score };
                })
                .filter(Boolean)
                .sort((a, b) => a.score - b.score)
                .map(({ item }) => item)
                .slice(0, 12);

            const raw = this.query.trim();
            const lookups = this.searches.map((search) => ({
                ...search,
                label: search.label.replace(':q', raw),
                href: search.href + encodeURIComponent(raw),
            }));

            return [...scored, ...lookups];
        },

        show() {
            this.open = true;
            this.query = '';
            this.active = 0;
            this.$nextTick(() => this.$refs.input?.focus());
        },

        hide() {
            this.open = false;
        },

        shortcut(event) {
            const key = event.key?.toLowerCase();

            if ((event.ctrlKey || event.metaKey) && (key === 'k' || key === 'g')) {
                event.preventDefault();
                this.open ? this.hide() : this.show();
                return;
            }

            if (this.open && key === 'escape') {
                event.preventDefault();
                this.hide();
                return;
            }

            // "/" membuka pencarian bila kursor tidak sedang di kolom isian.
            const target = event.target;
            const typing = target instanceof HTMLElement
                && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));

            if (!this.open && key === '/' && !typing && !event.ctrlKey && !event.metaKey && !event.altKey) {
                event.preventDefault();
                this.show();
            }
        },

        navigate(event) {
            const total = this.results.length;

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                this.active = total ? (this.active + 1) % total : 0;
                this.scrollToActive();
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                this.active = total ? (this.active - 1 + total) % total : 0;
                this.scrollToActive();
            } else if (event.key === 'Enter') {
                event.preventDefault();
                const entry = this.results[this.active];
                if (entry) this.go(entry);
            }
        },

        scrollToActive() {
            this.$nextTick(() => {
                this.$refs.list?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' });
            });
        },

        go(entry) {
            this.hide();

            if (typeof window.wmsNavigate === 'function') {
                window.wmsNavigate(entry.href);
            } else {
                window.location.href = entry.href;
            }
        },

        icon(name) {
            return this.$refs.icons?.querySelector(`[data-icon="${name}"]`)?.innerHTML ?? '';
        },

        init() {
            this.$watch('query', () => (this.active = 0));
        },
    };
}
