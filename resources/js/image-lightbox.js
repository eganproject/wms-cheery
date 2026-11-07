/**
 * Pratinjau gambar barang.
 *
 * Link apa pun dengan atribut data-lightbox membuka gambarnya (href) di
 * lapisan besar, tanpa pindah halaman. Atribut pendukung:
 *   data-lightbox="grup"      link dengan grup sama bisa digeser panah kiri/kanan
 *   data-lightbox-title       judul (nama barang)
 *   data-lightbox-caption     keterangan kecil (SKU)
 *
 * Ctrl/Cmd/klik tengah tetap membuka tab baru seperti link biasa.
 * Gambar kecil yang gagal dimuat (berkas hilang) diganti ikon kotak lewat
 * kelas .thumb-broken pada pembungkus [data-thumb].
 */

let box = null;
let items = [];
let index = 0;
let lastFocus = null;

function parts() {
    box ??= document.getElementById('image-lightbox');
    if (!box) return null;

    return {
        img: box.querySelector('[data-part="image"]'),
        title: box.querySelector('[data-part="title"]'),
        caption: box.querySelector('[data-part="caption"]'),
        counter: box.querySelector('[data-part="counter"]'),
        original: box.querySelector('[data-part="original"]'),
        error: box.querySelector('[data-part="error"]'),
        loading: box.querySelector('[data-part="loading"]'),
        prev: box.querySelector('[data-part="prev"]'),
        next: box.querySelector('[data-part="next"]'),
    };
}

function show(position) {
    const el = parts();
    if (!el || !items.length) return;

    index = (position + items.length) % items.length;
    const link = items[index];
    const url = link.href;

    el.title.textContent = link.dataset.lightboxTitle || '';
    el.caption.textContent = link.dataset.lightboxCaption || '';
    el.caption.hidden = !link.dataset.lightboxCaption;
    el.original.href = url;
    el.counter.textContent = items.length > 1 ? `${index + 1} / ${items.length}` : '';
    el.prev.hidden = el.next.hidden = items.length < 2;

    el.error.hidden = true;
    el.loading.hidden = false;
    el.img.hidden = true;
    el.img.alt = link.dataset.lightboxTitle || 'Gambar barang';
    el.img.onload = () => {
        el.loading.hidden = true;
        el.img.hidden = false;
    };
    el.img.onerror = () => {
        el.loading.hidden = true;
        el.error.hidden = false;
    };
    el.img.src = url;
}

function open(link) {
    const el = parts();
    if (!el) return false;

    const group = link.dataset.lightbox;
    items = group
        ? [...document.querySelectorAll('a[data-lightbox]')].filter((a) => a.dataset.lightbox === group && a.offsetParent !== null)
        : [link];
    if (!items.includes(link)) items = [link];

    lastFocus = document.activeElement;
    box.hidden = false;
    document.documentElement.classList.add('overflow-hidden');
    show(items.indexOf(link));
    box.querySelector('[data-part="close"]').focus({ preventScroll: true });

    return true;
}

function close() {
    if (!box || box.hidden) return;

    box.hidden = true;
    document.documentElement.classList.remove('overflow-hidden');
    const el = parts();
    el.img.removeAttribute('src');
    items = [];
    lastFocus?.focus?.({ preventScroll: true });
}

export default function initImageLightbox() {
    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0) return;

        const action = event.target.closest('#image-lightbox [data-action]');
        if (action) {
            event.preventDefault();
            ({ close, prev: () => show(index - 1), next: () => show(index + 1) })[action.dataset.action]?.();
            return;
        }

        // Gambar kecil yang rusak: jangan buka tab berisi gambar kosong.
        if (event.target.closest('[data-thumb].thumb-broken')) {
            event.preventDefault();
            return;
        }

        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        const link = event.target.closest('a[data-lightbox]');
        if (link && open(link)) event.preventDefault();
    });

    document.addEventListener('keydown', (event) => {
        if (!box || box.hidden) return;

        if (event.key === 'Escape') {
            event.preventDefault();
            close();
        } else if (event.key === 'ArrowLeft' && items.length > 1) {
            show(index - 1);
        } else if (event.key === 'ArrowRight' && items.length > 1) {
            show(index + 1);
        }
    });

    // Navigasi AJAX menukar isi halaman: jangan biarkan pratinjau tertinggal.
    window.addEventListener('popstate', close);
    window.addEventListener('page-navigated', close);

    // Event error tidak menggelembung, jadi ditangkap di fase capture.
    document.addEventListener(
        'error',
        (event) => {
            const img = event.target;
            if (img.tagName !== 'IMG') return;

            const wrap = img.closest('[data-thumb]');
            if (!wrap) return;

            wrap.classList.add('thumb-broken');
            wrap.removeAttribute('data-lightbox');
            wrap.title = 'Gambar tidak bisa dimuat';
        },
        true,
    );
}
