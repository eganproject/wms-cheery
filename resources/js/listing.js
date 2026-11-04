/**
 * Kaki tabel bersama (<x-ui.pagination>): pilih jumlah baris per halaman dan
 * lompat ke halaman tertentu.
 *
 * Sengaja tanpa <form>: banyak tabel berada di dalam form aksi massal, dan
 * form tidak boleh bersarang. Jadi URL tujuannya dirakit di sini lalu dibuka
 * lewat navigasi AJAX.
 */
function go(base, param, value, resetParam) {
    const url = new URL(base, location.href);
    url.searchParams.set(param, value);
    if (resetParam) url.searchParams.delete(resetParam);

    if (typeof window.wmsNavigate === 'function') {
        window.wmsNavigate(url.toString());
    } else {
        window.location.href = url.toString();
    }
}

document.addEventListener('change', (event) => {
    const select = event.target.closest('select[data-per-page]');
    if (!select) return;

    go(select.dataset.url, select.dataset.param, select.value, select.dataset.pageParam);
});

function jump(input) {
    const max = Number(input.dataset.max) || 1;
    const page = Math.min(Math.max(parseInt(input.value, 10) || 0, 1), max);
    if (!input.value.trim()) return;

    go(input.dataset.url, input.dataset.param, page);
}

document.addEventListener('keydown', (event) => {
    const input = event.target.closest?.('input[data-page-jump]');
    if (!input || event.key !== 'Enter') return;

    // Jangan sampai Enter ikut mengirim form aksi massal di sekelilingnya.
    event.preventDefault();
    jump(input);
});
