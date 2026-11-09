<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Peta menu aplikasi, bergaya sidebar workspace ERPNext.
 *
 * Satu sumber untuk menu samping, breadcrumb di bilah atas, dan pencarian
 * cepat (Ctrl+K). Halaman dikelompokkan menurut
 * pekerjaannya:
 *
 *  - Penjualan Marketplace: pekerjaan harian pesanan online — pesanan, packing,
 *    antrean kirim, retur, dan resi.
 *  - Transaksi Stok: dokumen yang menggerakkan saldo.
 *  - Master Data: barang dan pemasok.
 *  - Absensi: kehadiran, karyawan, dan jam kerja.
 *  - Laporan: semua yang hanya dibaca.
 *  - Pengaturan: pengguna dan akses.
 *
 * Supaya menu samping tetap pendek, halaman yang saling berkaitan digabung
 * di bawah satu menu dan dibuka lewat tab di atas halaman ('tabs'). Setiap
 * tab tetap punya alamat dan hak aksesnya sendiri; tab tidak pernah punya
 * menu samping sendiri, jadi tidak ada menu ganda.
 */
class Navigation
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected static function definition(): array
    {
        return [
            [
                'key' => 'home',
                'label' => null,
                'items' => [
                    ['label' => 'Beranda', 'icon' => 'home', 'route' => 'admin.dashboard',
                        'match' => 'admin.dashboard', 'can' => null,
                        'keywords' => 'dashboard ringkasan workspace'],
                    ['label' => 'Persetujuan', 'icon' => 'approval', 'route' => 'admin.approvals.index',
                        'match' => 'admin.approvals.*', 'can' => 'approvals.view',
                        'badge' => fn () => ApprovalCounter::pending() ?: null,
                        'keywords' => 'approval menunggu persetujuan setujui'],
                ],
            ],
            [
                'key' => 'marketplace',
                'label' => 'Penjualan Marketplace',
                'icon' => 'marketplace',
                'items' => [
                    ['label' => 'Pesanan', 'icon' => 'shopping-cart', 'tabs' => [
                        ['label' => 'Daftar Pesanan', 'icon' => 'shopping-cart', 'route' => 'admin.orders.index',
                            'match' => ['admin.orders.index', 'admin.orders.priority'], 'can' => 'imports.view',
                            // Dokumen pengiriman pesanan marketplace dibuka dari
                            // Pesanan, jadi detailnya juga berada di bawah menu ini.
                            'also' => fn () => static::viewingMarketplaceOutbound(),
                            'keywords' => 'pesanan order marketplace shopee tiktok resi status resi lacak duplikat kembar batal proses'],
                        ['label' => 'Packing List', 'icon' => 'list', 'route' => 'admin.orders.packing',
                            'match' => 'admin.orders.packing', 'can' => 'imports.view',
                            'keywords' => 'packing list picking daftar barang dikemas sisa remaining resi import'],
                        ['label' => 'Import Resi', 'icon' => 'import', 'route' => 'admin.imports.index',
                            'match' => ['admin.imports.index', 'admin.imports.show', 'admin.imports.create', 'admin.imports.batches'],
                            'can' => 'imports.view',
                            'create' => ['route' => 'admin.imports.create', 'can' => 'imports.create'],
                            'keywords' => 'import tiktok excel data resi unggah'],
                    ]],
                    ['label' => 'Pre Order', 'icon' => 'clock', 'tabs' => [
                        ['label' => 'Tenggat PO', 'icon' => 'clock', 'route' => 'admin.orders.deadlines',
                            'match' => 'admin.orders.deadlines*', 'can' => 'imports.view',
                            'keywords' => 'tenggat po pre order batas kirim deadline lead time jatuh tempo lewat batas hari ini besok jumlah resi rencana produksi'],
                        ['label' => 'Lama PO', 'icon' => 'calendar', 'route' => 'admin.po-periods.index',
                            'match' => 'admin.po-periods.*', 'can' => 'imports.view',
                            'keywords' => 'lama po pre order preorder batas kirim harus dikirim deadline sla normal'],
                    ]],
                    ['label' => 'Stasiun Gudang', 'icon' => 'packing', 'tabs' => [
                        ['label' => 'QC Kemas', 'icon' => 'packing', 'route' => 'admin.outbounds.marketplace',
                            'match' => ['admin.outbounds.marketplace', 'admin.outbounds.scan'], 'can' => 'outbounds.scan',
                            'keywords' => 'qc kemas stasiun packing scan qc resi pesanan marketplace'],
                        ['label' => 'Serah Terima Paket', 'icon' => 'outbound', 'route' => 'admin.outbounds.handover',
                            'match' => 'admin.outbounds.handover', 'can' => 'outbounds.view',
                            'badge' => fn () => HandoverQueueCounter::count() ?: null,
                            'keywords' => 'serah terima paket handover kurir kirim siap kirim antrean'],
                        ['label' => 'Retur Marketplace', 'icon' => 'scan', 'route' => 'admin.returns.marketplace',
                            'match' => ['admin.returns.marketplace', 'admin.returns.scan'], 'can' => 'returns.scan',
                            'keywords' => 'retur scan stasiun pengembalian'],
                    ]],
                ],
            ],
            [
                'key' => 'stock',
                'label' => 'Transaksi Stok',
                'icon' => 'layers',
                'items' => [
                    ['label' => 'Penerimaan', 'icon' => 'inbound', 'tabs' => [
                        ['label' => 'Barang Masuk', 'icon' => 'inbound', 'route' => 'admin.inbounds.index',
                            'match' => 'admin.inbounds.*', 'can' => 'inbounds.view',
                            'create' => ['route' => 'admin.inbounds.create', 'can' => 'inbounds.create'],
                            'keywords' => 'barang masuk penerimaan barang pemasok inbound purchase receipt'],
                        ['label' => 'Retur', 'icon' => 'return', 'route' => 'admin.returns.index',
                            'match' => ['admin.returns.index', 'admin.returns.show', 'admin.returns.create', 'admin.returns.edit'],
                            'can' => 'returns.view',
                            'create' => ['route' => 'admin.returns.create', 'can' => 'returns.create'],
                            'search' => 'Penerimaan Retur',
                            'keywords' => 'retur pengembalian return penerimaan retur'],
                    ]],
                    ['label' => 'Penjualan Manual', 'icon' => 'outbound', 'route' => 'admin.outbounds.index',
                        'match' => ['admin.outbounds.index', 'admin.outbounds.show', 'admin.outbounds.create', 'admin.outbounds.edit'],
                        'except' => fn () => static::viewingMarketplaceOutbound(),
                        'can' => 'outbounds.view',
                        'create' => ['route' => 'admin.outbounds.create', 'can' => 'outbounds.create'],
                        'keywords' => 'barang keluar penjualan manual reguler pengiriman outbound delivery note'],
                    ['label' => 'Koreksi Stok', 'icon' => 'sliders', 'tabs' => [
                        ['label' => 'Stok Opname', 'icon' => 'opname', 'route' => 'admin.opnames.index',
                            'match' => 'admin.opnames.*', 'can' => 'opnames.view',
                            'create' => ['route' => 'admin.opnames.create', 'can' => 'opnames.create'],
                            'keywords' => 'hitung fisik stock take opname'],
                        ['label' => 'Penyesuaian', 'icon' => 'sliders', 'route' => 'admin.adjustments.index',
                            'match' => 'admin.adjustments.*', 'can' => 'adjustments.view',
                            'create' => ['route' => 'admin.adjustments.create', 'can' => 'adjustments.create'],
                            'search' => 'Penyesuaian Stok',
                            'keywords' => 'koreksi adjustment stock reconciliation penyesuaian stok'],
                        ['label' => 'Barang Rusak', 'icon' => 'damaged', 'route' => 'admin.disposals.index',
                            'match' => 'admin.disposals.*', 'can' => 'disposals.view',
                            'create' => ['route' => 'admin.disposals.create', 'can' => 'disposals.create'],
                            'keywords' => 'rusak pemusnahan disposal damaged retur pemasok supplier return klaim'],
                    ]],
                ],
            ],
            [
                'key' => 'master',
                'label' => 'Master Data',
                'icon' => 'box',
                'items' => [
                    ['label' => 'Barang', 'icon' => 'box', 'route' => 'admin.products.index',
                        'match' => 'admin.products.*', 'can' => 'products.view',
                        'create' => ['route' => 'admin.products.create', 'can' => 'products.create'],
                        'keywords' => 'item produk sku barcode stok kartu stok daftar barang varian variasi warna ukuran'],
                    ['label' => 'Pemasok', 'icon' => 'supplier', 'route' => 'admin.suppliers.index',
                        'match' => 'admin.suppliers.*', 'can' => 'suppliers.view',
                        'create' => ['route' => 'admin.suppliers.create', 'can' => 'suppliers.create'],
                        'keywords' => 'supplier vendor'],
                ],
            ],
            [
                'key' => 'attendance',
                'label' => 'Absensi',
                'icon' => 'clock',
                'items' => [
                    ['label' => 'Absensi', 'icon' => 'clock', 'tabs' => [
                        ['label' => 'Harian', 'icon' => 'clock', 'route' => 'admin.attendance.index',
                            'match' => ['admin.attendance.index', 'admin.attendance.employee'], 'can' => 'attendance.view',
                            'search' => 'Absensi Harian',
                            'keywords' => 'absensi absen hadir telat masuk pulang fingerprint selfie karyawan hari ini harian'],
                        ['label' => 'Izin, Sakit & Cuti', 'icon' => 'calendar', 'route' => 'admin.attendance-leaves.index',
                            'match' => 'admin.attendance-leaves.*', 'can' => 'leaves.view',
                            'create' => ['route' => 'admin.attendance-leaves.create', 'can' => 'leaves.create'],
                            'keywords' => 'izin sakit cuti libur tidak masuk surat dokter'],
                        ['label' => 'Rekap', 'icon' => 'calendar-days', 'route' => 'admin.attendance.report',
                            'match' => 'admin.attendance.report', 'can' => 'attendance.view',
                            'search' => 'Rekap Absensi',
                            'keywords' => 'rekap absensi bulanan kehadiran persen telat tidak hadir'],
                    ]],
                    ['label' => 'Karyawan', 'icon' => 'users', 'tabs' => [
                        ['label' => 'Karyawan', 'icon' => 'users', 'route' => 'admin.employees.index',
                            'match' => 'admin.employees.*', 'can' => 'employees.view',
                            'create' => ['route' => 'admin.employees.create', 'can' => 'employees.create'],
                            'keywords' => 'karyawan pegawai staf pin mesin metode absen'],
                        ['label' => 'Jabatan', 'icon' => 'shield', 'route' => 'admin.job-positions.index',
                            'match' => 'admin.job-positions.*', 'can' => 'employees.view',
                            'keywords' => 'jabatan posisi role bawaan hak akses karyawan'],
                        ['label' => 'Jam Kerja', 'icon' => 'clock', 'route' => 'admin.work-shifts.index',
                            'match' => 'admin.work-shifts.*', 'can' => 'work-shifts.view',
                            'keywords' => 'jam kerja shift toleransi telat hari libur nasional cuti bersama'],
                        ['label' => 'Lokasi & Mesin Absen', 'icon' => 'map-pin', 'route' => 'admin.attendance-settings.index',
                            'match' => 'admin.attendance-settings.*', 'can' => 'attendance-settings.view',
                            'keywords' => 'lokasi gps radius mesin fingerprint zkteco selfie absen hp'],
                    ]],
                ],
            ],
            [
                'key' => 'report',
                'label' => 'Laporan',
                'icon' => 'report',
                'items' => [
                    ['label' => 'Laporan Stok', 'icon' => 'chart', 'tabs' => [
                        ['label' => 'Saldo Stok', 'icon' => 'chart', 'route' => 'admin.reports.stock',
                            'match' => 'admin.reports.stock', 'can' => 'reports.view',
                            'keywords' => 'stock balance saldo laporan stok'],
                        ['label' => 'Mutasi Stok', 'icon' => 'move', 'route' => 'admin.movements.index',
                            'match' => 'admin.movements.*', 'can' => 'movements.view',
                            'keywords' => 'stock ledger pergerakan riwayat'],
                        ['label' => 'Kebutuhan Restock', 'icon' => 'restock', 'route' => 'admin.reports.restock',
                            'match' => 'admin.reports.restock', 'can' => 'reports.view',
                            'keywords' => 'restock reorder menipis pembelian'],
                    ]],
                    ['label' => 'Laporan Penjualan', 'icon' => 'trending-up', 'tabs' => [
                        // Ringkasan Bisnis punya tab isi sendiri (?tab=) di dalam halamannya.
                        ['label' => 'Ringkasan Bisnis', 'icon' => 'trending-up', 'route' => 'admin.reports.owner',
                            'match' => 'admin.reports.owner*', 'can' => 'owner-reports.view',
                            'keywords' => 'ringkasan bisnis laporan owner pemilik omzet laba hpp penjualan pendapatan terlaris tidak laku retur ketepatan kirim telat penalti umur stok kinerja tim produktivitas'],
                        ['label' => 'Resi per Hari', 'icon' => 'calendar-days', 'route' => 'admin.imports.daily',
                            'match' => 'admin.imports.daily*', 'can' => 'imports.view',
                            'keywords' => 'resi harian rekap'],
                        ['label' => 'Resi per Ekspedisi', 'icon' => 'outbound', 'route' => 'admin.imports.couriers',
                            'match' => 'admin.imports.couriers', 'can' => 'imports.view',
                            'keywords' => 'kurir ekspedisi courier'],
                    ]],
                ],
            ],
            [
                'key' => 'settings',
                'label' => 'Pengaturan',
                'icon' => 'settings',
                'items' => [
                    ['label' => 'Pengguna & Akses', 'icon' => 'users', 'tabs' => [
                        ['label' => 'Pengguna', 'icon' => 'users', 'route' => 'admin.users.index',
                            'match' => 'admin.users.*', 'can' => 'users.view',
                            'create' => ['route' => 'admin.users.create', 'can' => 'users.create'],
                            'keywords' => 'user akun karyawan'],
                        ['label' => 'Role', 'icon' => 'shield', 'route' => 'admin.roles.index',
                            'match' => 'admin.roles.*', 'can' => 'roles.view',
                            'create' => ['route' => 'admin.roles.create', 'can' => 'roles.create'],
                            'keywords' => 'peran jabatan'],
                        ['label' => 'Hak Akses', 'icon' => 'key', 'route' => 'admin.permissions.index',
                            'match' => 'admin.permissions.*', 'can' => 'permissions.view',
                            'keywords' => 'permission izin matriks'],
                    ]],
                    ['label' => 'Aplikasi & Integrasi', 'icon' => 'phone', 'tabs' => [
                        ['label' => 'Aplikasi Android', 'icon' => 'phone', 'route' => 'admin.mobile-app.index',
                            'match' => 'admin.mobile-app.*', 'can' => 'mobile-app.view',
                            'keywords' => 'apk android hp update pembaruan versi scan'],
                        ['label' => 'Akses API Stok', 'icon' => 'api', 'route' => 'admin.stock-api-access.index',
                            'match' => 'admin.stock-api-access.*', 'can' => 'stock-api-access.view',
                            'keywords' => 'api ip whitelist integrasi'],
                    ]],
                ],
            ],
        ];
    }

    /**
     * Kelompok menu yang boleh dilihat pengguna saat ini, lengkap dengan
     * status aktif. Kelompok tanpa satu pun halaman yang boleh dibuka hilang.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function sections(): array
    {
        // Dipakai menu samping, breadcrumb, dan pencarian dalam satu
        // permintaan — cukup dihitung sekali.
        $attributes = request()->attributes;

        if ($attributes->has('navigation.sections')) {
            return $attributes->get('navigation.sections');
        }

        $sections = (function () {
            $user = auth()->user();

            return collect(static::definition())
                ->map(function (array $section) use ($user) {
                    $items = collect($section['items'])
                        ->map(function (array $item) use ($user) {
                            // Menu tanpa tab diperlakukan sebagai satu tab.
                            $tabs = collect($item['tabs'] ?? [$item])
                                ->filter(fn (array $tab) => $tab['can'] === null || $user?->can($tab['can']))
                                ->map(fn (array $tab) => static::resolvePage($tab, $user))
                                ->values();

                            if ($tabs->isEmpty()) {
                                return null;
                            }

                            $first = $tabs->first();
                            $badge = $tabs->sum(fn (array $tab) => (int) $tab['badge']);

                            return [
                                'label' => $item['label'],
                                'icon' => $item['icon'],
                                'route' => $first['route'],
                                'href' => $first['href'],
                                'active' => $tabs->contains('active', true),
                                'badge' => $badge ?: null,
                                'keywords' => $tabs->pluck('keywords')->implode(' '),
                                'create' => isset($item['tabs']) ? null : $first['create'],
                                // Tab hanya tampil bila menu memang dibagi tab.
                                'tabs' => isset($item['tabs']) ? $tabs->all() : [],
                            ];
                        })
                        ->filter()
                        ->values()
                        ->all();

                    return [
                        'key' => $section['key'],
                        'label' => $section['label'],
                        'icon' => $section['icon'] ?? null,
                        'items' => $items,
                        'active' => collect($items)->contains('active', true),
                    ];
                })
                ->filter(fn (array $section) => count($section['items']) > 0)
                ->values()
                ->all();
        })();

        $attributes->set('navigation.sections', $sections);

        return $sections;
    }

    /**
     * Satu halaman (tab) yang boleh dibuka, lengkap dengan status aktifnya.
     *
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    protected static function resolvePage(array $page, $user): array
    {
        return [
            'label' => $page['label'],
            'search' => $page['search'] ?? $page['label'],
            'icon' => $page['icon'],
            'route' => $page['route'],
            'href' => route($page['route']),
            'active' => (request()->routeIs($page['match']) && ! (isset($page['except']) && ($page['except'])()))
                || (isset($page['also']) && ($page['also'])()),
            'badge' => isset($page['badge']) ? ($page['badge'])() : null,
            'keywords' => $page['keywords'] ?? '',
            'create' => isset($page['create']) && $user?->can($page['create']['can'])
                ? route($page['create']['route'])
                : null,
        ];
    }

    /**
     * Sedang membuka detail atau form dokumen pengiriman pesanan marketplace?
     *
     * Dokumen itu dibuat otomatis oleh QC Kemas dan dibuka dari Pesanan,
     * bukan bagian dari Penjualan Manual.
     */
    protected static function viewingMarketplaceOutbound(): bool
    {
        if (! request()->routeIs('admin.outbounds.show', 'admin.outbounds.edit')) {
            return false;
        }

        $outbound = request()->route('outbound');

        return $outbound instanceof \App\Models\Outbound && $outbound->isMarketplace();
    }

    /**
     * Kelompok dan halaman yang sedang dibuka.
     *
     * @return array{section: ?array<string, mixed>, item: ?array<string, mixed>, tab: ?array<string, mixed>}
     */
    public static function current(): array
    {
        foreach (static::sections() as $section) {
            foreach ($section['items'] as $item) {
                if ($item['active']) {
                    $tab = collect($item['tabs'])->firstWhere('active', true);

                    return ['section' => $section, 'item' => $item, 'tab' => $tab];
                }
            }
        }

        return ['section' => null, 'item' => null, 'tab' => null];
    }

    /**
     * Tab halaman yang sedang dibuka, ditampilkan di atas isi halaman.
     *
     * Hanya muncul di halaman utama tiap tab (daftar), bukan di detail atau
     * form, dan hanya bila pengguna boleh membuka lebih dari satu tab.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function tabs(): array
    {
        ['item' => $item, 'tab' => $tab] = static::current();

        if (! $item || ! $tab || count($item['tabs']) < 2 || ! request()->routeIs($tab['route'])) {
            return [];
        }

        return $item['tabs'];
    }

    /**
     * Breadcrumb navbar: kelompok › halaman › (judul halaman detail).
     *
     * @return array<int, array{label: string, href: ?string}>
     */
    public static function breadcrumbs(?string $title = null): array
    {
        ['section' => $section, 'item' => $item, 'tab' => $tab] = static::current();

        if (! $item) {
            return $title ? [['label' => $title, 'href' => null]] : [];
        }

        $crumbs = [];

        if ($section['label']) {
            $crumbs[] = ['label' => $section['label'], 'href' => null];
        }

        // Menu bertab: kelompok › menu › tab › (judul detail).
        $page = $tab && count($item['tabs']) > 1 ? $tab : null;
        $onIndex = request()->routeIs(($page ?? $item)['route']);

        if ($page) {
            $crumbs[] = ['label' => $item['label'], 'href' => $item['href']];
        }

        $last = $page ?? $item;
        $crumbs[] = ['label' => $last['label'], 'href' => $onIndex ? null : $last['href']];

        if (! $onIndex && $title && $title !== $last['label']) {
            $crumbs[] = ['label' => $title, 'href' => null];
        }

        return $crumbs;
    }

    /**
     * Isi pencarian cepat: semua halaman yang boleh dibuka, ditambah pintasan
     * "buat baru" untuk dokumen yang boleh dibuat.
     *
     * @return Collection<int, array<string, string>>
     */
    public static function searchIndex(): Collection
    {
        $entries = collect();

        foreach (static::sections() as $section) {
            foreach ($section['items'] as $item) {
                // Setiap tab tetap bisa dicari langsung dengan namanya sendiri.
                $pages = $item['tabs'] ?: [array_merge($item, ['search' => $item['label']])];

                foreach ($pages as $page) {
                    $entries->push([
                        'label' => $page['search'],
                        'group' => $section['label'] ?? 'Umum',
                        'href' => $page['href'],
                        'icon' => $page['icon'],
                        'keywords' => trim($page['keywords'].' '.$item['label']),
                    ]);

                    if ($page['create']) {
                        $entries->push([
                            'label' => 'Buat '.$page['search'].' Baru',
                            'group' => 'Buat Baru',
                            'href' => $page['create'],
                            'icon' => 'plus',
                            'keywords' => 'tambah baru new '.$page['keywords'],
                        ]);
                    }
                }
            }
        }

        $entries->push([
            'label' => 'Profil Saya',
            'group' => 'Akun',
            'href' => route('profile.edit'),
            'icon' => 'user',
            'keywords' => 'akun password kata sandi profil',
        ]);

        return $entries;
    }
}
