/**
 * Tooltip penjelasan.
 *
 * Satu kotak #hint-tip dipakai bersama oleh semua elemen ber-atribut
 * data-hint (opsional data-hint-title dan data-hint-next). Kotaknya:
 *
 *  - muncul setelah jeda singkat saat disorot kursor, supaya tidak berkedip
 *    ketika kursor hanya lewat;
 *  - muncul saat elemen difokus keyboard;
 *  - di layar sentuh, ikon info (.hint-icon) dibuka dengan sekali ketuk dan
 *    ditutup dengan mengetuk di luar. Elemen lain (tautan, tab) tetap
 *    menjalankan aksinya — tooltip-nya muncul lewat tekan-tahan;
 *  - selalu muat di layar: diletakkan di bawah elemen, atau di atasnya bila
 *    tidak cukup ruang, dan lebarnya menyesuaikan layar ponsel.
 *
 * Dipasang lewat delegasi event di document, jadi ikut bekerja pada isi
 * halaman yang ditukar navigasi AJAX tanpa perlu dipasang ulang.
 */
const GUTTER = 12;
const GAP = 8;

export default function initHints() {
    const tip = document.getElementById('hint-tip');
    if (! tip) return;

    const parts = {
        title: tip.querySelector('[data-part=title]'),
        body: tip.querySelector('[data-part=body]'),
        next: tip.querySelector('[data-part=next]'),
        nextText: tip.querySelector('[data-part=next-text]'),
        arrow: tip.querySelector('[data-part=arrow]'),
    };

    let current = null;
    let timer = null;
    let pressTimer = null;
    let lastPointer = 'mouse';

    document.addEventListener('pointerdown', (event) => {
        lastPointer = event.pointerType || 'mouse';
    }, { passive: true, capture: true });

    const place = (target) => {
        const rect = target.getBoundingClientRect();
        const width = tip.offsetWidth;
        const height = tip.offsetHeight;
        const left = Math.min(
            Math.max(GUTTER, rect.left + rect.width / 2 - width / 2),
            window.innerWidth - width - GUTTER,
        );
        const below = rect.bottom + GAP + height <= window.innerHeight - GUTTER || rect.top - GAP - height < GUTTER;

        tip.style.left = `${left}px`;
        tip.style.top = `${below ? rect.bottom + GAP : rect.top - height - GAP}px`;

        const arrowLeft = Math.min(Math.max(12, rect.left + rect.width / 2 - left - 5), width - 22);
        parts.arrow.style.left = `${arrowLeft}px`;
        parts.arrow.style.top = below ? '-6px' : 'auto';
        parts.arrow.style.bottom = below ? 'auto' : '-6px';
        parts.arrow.style.transform = below ? 'rotate(45deg)' : 'rotate(225deg)';
    };

    const open = (target) => {
        const text = target?.dataset.hint;
        if (! text) return;

        clearTimeout(timer);
        current = target;

        const title = target.dataset.hintTitle || '';
        const next = target.dataset.hintNext || '';

        parts.title.textContent = title;
        parts.title.classList.toggle('hidden', ! title);
        parts.body.textContent = text;
        parts.body.classList.toggle('mt-1', !! title);
        parts.nextText.textContent = next;
        parts.next.classList.toggle('hidden', ! next);

        tip.classList.remove('hidden');
        tip.setAttribute('aria-hidden', 'false');
        place(target);
        requestAnimationFrame(() => tip.classList.remove('opacity-0'));
    };

    const close = () => {
        clearTimeout(timer);
        current = null;
        tip.classList.add('hidden', 'opacity-0');
        tip.setAttribute('aria-hidden', 'true');
    };

    const hintOf = (node) => node?.closest?.('[data-hint]') ?? null;

    // Kursor (bukan sentuhan: event mouse tiruan dari ketukan diabaikan).
    document.addEventListener('pointerover', (event) => {
        if (event.pointerType === 'touch') return;

        const target = hintOf(event.target);
        if (target === current) return;

        clearTimeout(timer);

        if (! target) {
            if (current) timer = setTimeout(close, 80);
            return;
        }

        timer = setTimeout(() => open(target), current ? 0 : 250);
    });

    document.addEventListener('focusin', (event) => {
        const target = hintOf(event.target);
        if (target && event.target.matches(':focus-visible')) open(target);
        else if (current && ! target) close();
    });

    document.addEventListener('focusout', (event) => {
        if (hintOf(event.target) === current) close();
    });

    // Ketuk ikon info untuk membuka / menutup; ketuk di luar untuk menutup.
    document.addEventListener('click', (event) => {
        const icon = event.target.closest?.('.hint-icon[data-hint]');

        if (icon) {
            event.preventDefault();
            event.stopPropagation();
            // Sentuhan: ketuk lagi untuk menutup. Kursor: tooltip-nya sudah
            // terbuka karena disorot, jadi klik tidak boleh menutupnya.
            if (current === icon && lastPointer === 'touch') close();
            else open(icon);
            return;
        }

        if (current && ! hintOf(event.target)) close();
    }, true);

    // Tekan-tahan di layar sentuh membuka tooltip elemen apa pun (tab, kolom, lencana).
    document.addEventListener('touchstart', (event) => {
        const target = hintOf(event.target);
        clearTimeout(pressTimer);
        if (! target || target.classList.contains('hint-icon')) return;

        pressTimer = setTimeout(() => open(target), 450);
    }, { passive: true });

    ['touchend', 'touchmove', 'touchcancel'].forEach((name) => {
        document.addEventListener(name, () => clearTimeout(pressTimer), { passive: true });
    });

    window.addEventListener('scroll', () => current && close(), { passive: true, capture: true });
    window.addEventListener('resize', () => current && close());
    window.addEventListener('keydown', (event) => event.key === 'Escape' && current && close());
    window.addEventListener('page-navigated', close);
}
