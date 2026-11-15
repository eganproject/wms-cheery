<x-app-layout :title="$fabric->sku">
    <x-ui.page-header :title="$fabric->displayName()" :subtitle="$fabric->sku.' · satuan '.$fabric->unit.($fabric->supplier ? ' · '.$fabric->supplier->name : '')"
                      :back="route('admin.fabrics.index')"
                      :status="$fabric->is_active ? null : 'Nonaktif'" statusVariant="gray">
        <x-slot name="actions">
            @can('fabrics.receive')
                <x-ui.button :href="route('admin.fabric-receipts.create', ['fabric' => $fabric->id])" icon="inbound">Catat Kain Masuk</x-ui.button>
            @endcan
            @can('fabrics.manage')
                <x-ui.button :href="route('admin.fabrics.edit', $fabric)" variant="secondary" icon="pencil">Ubah</x-ui.button>
                @if ($rolls->isEmpty() && $emptyRolls === 0)
                    <x-ui.confirm-delete :action="route('admin.fabrics.destroy', $fabric)" title="Hapus jenis kain ini?"
                                         :description="$fabric->sku.' belum punya roll dan akan dihapus.'" />
                @endif
            @endcan
        </x-slot>
    </x-ui.page-header>

    <div class="flex flex-col gap-4 sm:flex-row">
    <x-ui.fabric-image :fabric="$fabric" size="xl" class="self-center sm:self-auto" />
    <div class="grid flex-1 grid-cols-2 content-start gap-4 xl:grid-cols-4">
        <x-ui.stat-card label="Sisa Kain" :value="$fabric->format((float) $fabric->stock)" icon="layers" accent
                        :hint="$rolls->where('quantity', '>', 0)->count().' roll berisi'" />
        <x-ui.stat-card label="Stok Minimum" :value="$fabric->min_stock > 0 ? $fabric->format($fabric->min_stock) : '—'" icon="warning"
                        :hint="$fabric->min_stock > 0 && (float) $fabric->stock < $fabric->min_stock ? 'Di bawah minimum' : 'Aman'" />
        <x-ui.stat-card label="Roll Habis" :value="number_format($emptyRolls, 0, ',', '.')" icon="x-circle" hint="Roll yang sudah terpakai semua" />
        <x-ui.stat-card label="Dipakai untuk" :value="$usages->count().' barang'" icon="box" hint="Untuk menghitung kebutuhan kain" />
    </div>
    </div>

    <div class="mt-5 space-y-6">
        <x-ui.card title="Roll" :subtitle="$showEmpty ? 'Semua roll, termasuk yang sudah habis.' : 'Roll yang masih berisi. Centang lalu cetak label QR bila perlu cetak ulang.'" padding="p-0">
            <x-slot name="actions">
                <x-ui.button :href="route('admin.fabrics.show', [$fabric, 'habis' => $showEmpty ? null : 1])" variant="ghost" size="sm">
                    {{ $showEmpty ? 'Sembunyikan roll habis' : 'Tampilkan roll habis ('.$emptyRolls.')' }}
                </x-ui.button>
            </x-slot>

            @if ($rolls->isEmpty())
                <x-ui.empty-state icon="qr-code" title="Belum ada roll berisi"
                                  description="Catat kain masuk untuk menambah roll. Setiap roll mendapat label QR sendiri." />
            @else
                @include('admin.fabric-labels.rolls', ['rolls' => $rolls->each->setRelation('fabric', $fabric)])
            @endif
        </x-ui.card>

        @include('admin.fabrics.partials.usages')

        <x-ui.card title="Riwayat Terakhir" subtitle="30 mutasi kain terakhir. Riwayat lengkap kain keluar ada di tab Kain Keluar." padding="p-0">
            @if ($movements->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-ink-400">Belum ada mutasi.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="erp-table">
                        <thead>
                            <tr>
                                <th>Waktu</th>
                                <th>Jenis</th>
                                <th>Roll</th>
                                <th class="text-right">Jumlah</th>
                                <th class="text-right">Sisa Roll</th>
                                <th>Keterangan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($movements as $movement)
                                <tr class="{{ $movement->isCancelled() ? 'opacity-60' : '' }}">
                                    <td class="whitespace-nowrap text-sm text-ink-600">{{ $movement->moved_at->format('d/m/Y H:i') }}</td>
                                    <td>
                                        <x-ui.badge :variant="match ($movement->type) { 'in' => 'success', 'out' => 'warning', 'cancel' => 'info', default => 'purple' }">
                                            {{ $movement->typeLabel() }}{{ $movement->isCancelled() ? ' (dibatalkan)' : '' }}
                                        </x-ui.badge>
                                    </td>
                                    <td class="font-mono text-sm">{{ $movement->roll->code }}</td>
                                    <td class="text-right text-sm font-semibold tabular-nums {{ $movement->quantity < 0 ? 'text-rose-600' : 'text-emerald-700' }}">
                                        {{ ($movement->quantity > 0 ? '+' : '').$fabric->format($movement->quantity) }}
                                    </td>
                                    <td class="text-right text-sm tabular-nums">{{ $fabric->format($movement->balance_after) }}</td>
                                    <td class="text-sm text-ink-600">
                                        {{ collect([$movement->receipt?->code, $movement->productLabel() ? 'untuk '.$movement->productLabel() : null, $movement->note, $movement->user?->name])->filter()->implode(' · ') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>
    </div>
</x-app-layout>
