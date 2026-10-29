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
 *  - Laporan: semua yang hanya dibaca.
 *  - Pengaturan: pengguna dan akses.
 *
 * Setiap halaman bisa dibuka dengan satu klik dari menu samping, jadi
 * halaman tidak lagi memuat tab ke halaman lain yang sudah punya menu.
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
                    ['label' => 'Pesanan', 'icon' => 'shopping-cart', 'route' => 'admin.orders.index',
                        'match' => 'admin.orders.index', 'can' => 'imports.view',
                        'keywords' => 'pesanan order marketplace shopee tiktok resi status resi lacak duplikat kembar batal proses'],
                    ['label' => 'Packing List', 'icon' => 'list', 'route' => 'admin.orders.packing',
                        'match' => 'admin.orders.packing', 'can' => 'imports.view',
                        'keywords' => 'packing list picking daftar barang dikemas sisa remaining resi import'],
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
                    ['label' => 'Import Resi', 'icon' => 'import', 'route' => 'admin.imports.index',
                        'match' => ['admin.imports.index', 'admin.imports.show', 'admin.imports.create', 'admin.imports.batches'],
                        'can' => 'imports.view',
                        'create' => ['route' => 'admin.imports.create', 'can' => 'imports.create'],
                        'keywords' => 'import tiktok excel data resi unggah'],
                ],
            ],
            [
                'key' => 'stock',
                'label' => 'Transaksi Stok',
                'icon' => 'layers',
                'items' => [
                    ['label' => 'Barang Masuk', 'icon' => 'inbound', 'route' => 'admin.inbounds.index',
                        'match' => 'admin.inbounds.*', 'can' => 'inbounds.view',
                        'create' => ['route' => 'admin.inbounds.create', 'can' => 'inbounds.create'],
                        'keywords' => 'penerimaan pemasok inbound purchase receipt'],
                    ['label' => 'Barang Keluar', 'icon' => 'outbound', 'route' => 'admin.outbounds.index',
                        'match' => ['admin.outbounds.index', 'admin.outbounds.show', 'admin.outbounds.create', 'admin.outbounds.edit'],
                        'can' => 'outbounds.view',
                        'create' => ['route' => 'admin.outbounds.create', 'can' => 'outbounds.create'],
                        'keywords' => 'pengiriman outbound delivery note'],
                    ['label' => 'Penerimaan Retur', 'icon' => 'return', 'route' => 'admin.returns.index',
                        'match' => ['admin.returns.index', 'admin.returns.show', 'admin.returns.create', 'admin.returns.edit'],
                        'can' => 'returns.view',
                        'create' => ['route' => 'admin.returns.create', 'can' => 'returns.create'],
                        'keywords' => 'retur pengembalian return'],
                    ['label' => 'Stok Opname', 'icon' => 'opname', 'route' => 'admin.opnames.index',
                        'match' => 'admin.opnames.*', 'can' => 'opnames.view',
                        'create' => ['route' => 'admin.opnames.create', 'can' => 'opnames.create'],
                        'keywords' => 'hitung fisik stock take opname'],
                    ['label' => 'Penyesuaian Stok', 'icon' => 'sliders', 'route' => 'admin.adjustments.index',
                        'match' => 'admin.adjustments.*', 'can' => 'adjustments.view',
                        'create' => ['route' => 'admin.adjustments.create', 'can' => 'adjustments.create'],
                        'keywords' => 'koreksi adjustment stock reconciliation'],
                    ['label' => 'Barang Rusak', 'icon' => 'damaged', 'route' => 'admin.disposals.index',
                        'match' => 'admin.disposals.*', 'can' => 'disposals.view',
                        'create' => ['route' => 'admin.disposals.create', 'can' => 'disposals.create'],
                        'keywords' => 'rusak pemusnahan disposal damaged'],
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
                'key' => 'report',
                'label' => 'Laporan',
                'icon' => 'report',
                'items' => [
                    ['label' => 'Laporan Stok', 'icon' => 'chart', 'route' => 'admin.reports.stock',
                        'match' => 'admin.reports.stock', 'can' => 'reports.view',
                        'keywords' => 'stock balance saldo laporan'],
                    ['label' => 'Mutasi Stok', 'icon' => 'move', 'route' => 'admin.movements.index',
                        'match' => 'admin.movements.*', 'can' => 'movements.view',
                        'keywords' => 'stock ledger pergerakan riwayat'],
                    ['label' => 'Kebutuhan Restock', 'icon' => 'restock', 'route' => 'admin.reports.restock',
                        'match' => 'admin.reports.restock', 'can' => 'reports.view',
                        'keywords' => 'restock reorder menipis pembelian'],
                    ['label' => 'Resi per Hari', 'icon' => 'calendar-days', 'route' => 'admin.imports.daily',
                        'match' => 'admin.imports.daily*', 'can' => 'imports.view',
                        'keywords' => 'resi harian rekap'],
                    ['label' => 'Resi per Ekspedisi', 'icon' => 'outbound', 'route' => 'admin.imports.couriers',
                        'match' => 'admin.imports.couriers', 'can' => 'imports.view',
                        'keywords' => 'kurir ekspedisi courier'],
                ],
            ],
            [
                'key' => 'settings',
                'label' => 'Pengaturan',
                'icon' => 'settings',
                'items' => [
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
                    ['label' => 'Akses API Stok', 'icon' => 'api', 'route' => 'admin.stock-api-access.index',
                        'match' => 'admin.stock-api-access.*', 'can' => 'stock-api-access.view',
                        'keywords' => 'api ip whitelist integrasi'],
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
                        ->filter(fn (array $item) => $item['can'] === null || $user?->can($item['can']))
                        ->map(fn (array $item) => [
                            'label' => $item['label'],
                            'icon' => $item['icon'],
                            'route' => $item['route'],
                            'href' => route($item['route']),
                            'active' => request()->routeIs($item['match']),
                            'badge' => isset($item['badge']) ? ($item['badge'])() : null,
                            'keywords' => $item['keywords'] ?? '',
                            'create' => isset($item['create']) && $user?->can($item['create']['can'])
                                ? route($item['create']['route'])
                                : null,
                        ])
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
     * Kelompok dan halaman yang sedang dibuka.
     *
     * @return array{section: ?array<string, mixed>, item: ?array<string, mixed>}
     */
    public static function current(): array
    {
        foreach (static::sections() as $section) {
            foreach ($section['items'] as $item) {
                if ($item['active']) {
                    return ['section' => $section, 'item' => $item];
                }
            }
        }

        return ['section' => null, 'item' => null];
    }

    /**
     * Breadcrumb navbar: kelompok › halaman › (judul halaman detail).
     *
     * @return array<int, array{label: string, href: ?string}>
     */
    public static function breadcrumbs(?string $title = null): array
    {
        ['section' => $section, 'item' => $item] = static::current();

        if (! $item) {
            return $title ? [['label' => $title, 'href' => null]] : [];
        }

        $crumbs = [];

        if ($section['label']) {
            $crumbs[] = ['label' => $section['label'], 'href' => null];
        }

        $onIndex = request()->routeIs($item['route']);
        $crumbs[] = ['label' => $item['label'], 'href' => $onIndex ? null : $item['href']];

        if (! $onIndex && $title && $title !== $item['label']) {
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
                $entries->push([
                    'label' => $item['label'],
                    'group' => $section['label'] ?? 'Umum',
                    'href' => $item['href'],
                    'icon' => $item['icon'],
                    'keywords' => $item['keywords'],
                ]);

                if ($item['create']) {
                    $entries->push([
                        'label' => 'Buat '.$item['label'].' Baru',
                        'group' => 'Buat Baru',
                        'href' => $item['create'],
                        'icon' => 'plus',
                        'keywords' => 'tambah baru new '.$item['keywords'],
                    ]);
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
