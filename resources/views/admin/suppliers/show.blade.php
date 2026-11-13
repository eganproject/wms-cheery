<x-app-layout title="Detail Pemasok">
    <x-ui.page-header :title="$supplier->name" :subtitle="'Kode '.$supplier->code"
                      :back="route('admin.suppliers.index')">
        <x-slot name="actions">
            @can('suppliers.update')
                <x-ui.button :href="route('admin.suppliers.edit', $supplier)" icon="pencil">Ubah</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    @if ($returnSummary && $returnSummary['sent'] > 0)
        @php $num = fn ($value) => number_format((int) $value, 0, ',', '.'); @endphp
        <section class="mb-6" data-supplier-returns>
            <div class="mb-2 flex items-center justify-between gap-3">
                <h2 class="section-head !mb-0">Retur ke Pemasok</h2>
                <a href="{{ route('admin.disposals.index', ['tab' => 'pemasok', 'supplier_id' => $supplier->id, 'return_status' => 'all']) }}"
                   class="text-xs font-medium text-ink-600 underline-offset-4 hover:underline">Lihat semua retur</a>
            </div>
            <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
                <x-ui.stat-card label="Retur Berjalan" :value="$returnSummary['open']" icon="truck"
                                :hint="$num($returnSummary['waiting']).' unit masih ditunggu'.($returnSummary['late'] > 0 ? ' · '.$returnSummary['late'].' terlambat' : '')"
                                :href="route('admin.disposals.index', ['tab' => 'pemasok', 'supplier_id' => $supplier->id])" />
                <x-ui.stat-card label="Unit Dikirim" :value="$num($returnSummary['sent'])" icon="outbound"
                                :hint="$num($returnSummary['replaced']).' sudah diganti'" />
                <x-ui.stat-card label="Rata-rata Penggantian" info="Rata-rata hari dari barang dikirim sampai returnya selesai."
                                :value="$returnSummary['average_days'] !== null ? str_replace('.', ',', $returnSummary['average_days']).' hari' : '—'" icon="clock"
                                hint="Dari retur yang sudah selesai" />
                <x-ui.stat-card label="Ditolak Pemasok" info="Bagian unit yang ditolak, dari unit yang sudah diputuskan pemasok (diganti atau ditolak)."
                                :value="$returnSummary['rejected_rate'] !== null ? $returnSummary['rejected_rate'].'%' : '—'" icon="x-circle"
                                :hint="$num($returnSummary['rejected']).' unit ditolak'" />
            </div>
        </section>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-ui.card title="Informasi Kontak">
            <dl class="space-y-4 text-sm">
                @foreach ([
                    ['Nama kontak', $supplier->contact_name ?: '—', 'user'],
                    ['Telepon', $supplier->phone ?: '—', 'phone'],
                    ['Email', $supplier->email ?: '—', 'mail'],
                ] as [$label, $value, $icon])
                    <div class="flex items-start justify-between gap-3">
                        <dt class="flex items-center gap-2 text-ink-500">
                            <x-icon :name="$icon" class="h-4 w-4 text-ink-300" /> {{ $label }}
                        </dt>
                        <dd class="break-all text-right font-medium text-ink-950">{{ $value }}</dd>
                    </div>
                @endforeach

                @if ($supplier->address)
                    <div class="border-t border-ink-100 pt-4">
                        <dt class="text-ink-500">Alamat</dt>
                        <dd class="mt-1 whitespace-pre-line text-sm text-ink-800">{{ $supplier->address }}</dd>
                    </div>
                @endif

                <div class="flex items-center justify-between gap-3 border-t border-ink-100 pt-4">
                    <dt class="text-ink-500">Status</dt>
                    <dd>
                        <x-ui.badge :variant="$supplier->is_active ? 'success' : 'danger'">
                            {{ $supplier->is_active ? 'Aktif' : 'Nonaktif' }}
                        </x-ui.badge>
                    </dd>
                </div>
            </dl>
        </x-ui.card>

        <x-ui.card class="lg:col-span-2" title="Riwayat Penerimaan Barang"
                   subtitle="Dokumen penerimaan dari pemasok ini" padding="p-0">
            @if ($inbounds->isEmpty())
                <x-ui.empty-state icon="login" title="Belum ada dokumen"
                                  description="Dokumen penerimaan barang dari pemasok ini akan muncul di sini." />
            @else
                <div class="divide-y divide-ink-50">
                    @foreach ($inbounds as $inbound)
                        <a href="{{ route('admin.inbounds.show', $inbound) }}"
                           class="flex items-center gap-3 px-5 py-3.5 transition hover:bg-ink-50/50 sm:px-6">
                            <div class="min-w-0 flex-1">
                                <p class="font-mono text-sm font-medium text-ink-950">{{ $inbound->code }}</p>
                                <p class="text-xs text-ink-400">{{ $inbound->date->translatedFormat('d M Y') }}</p>
                            </div>
                            <p class="shrink-0 text-sm font-semibold text-ink-950">
                                {{ number_format((int) $inbound->items_sum_quantity, 0, ',', '.') }} unit
                            </p>
                            <x-ui.badge :variant="$inbound->statusVariant()" :icon="$inbound->statusIcon()">
                                {{ $inbound->statusLabel() }}
                            </x-ui.badge>
                        </a>
                    @endforeach
                </div>

                <x-ui.pagination :paginator="$inbounds" />
            @endif
        </x-ui.card>
    </div>
</x-app-layout>
