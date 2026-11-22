<x-app-layout title="Opname Kain">
    <x-ui.page-header title="Opname Kain" icon="opname"
                      subtitle="Hitung fisik semua roll sekaligus (per jenis kain atau lokasi), lalu terapkan selisihnya sebagai koreksi roll.">
        <x-slot name="actions">
            @can('fabrics.issue')
                <x-ui.button :href="route('admin.fabric-opnames.create')" icon="plus">Buat Opname</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white">
        @if ($opnames->isEmpty())
            <x-ui.empty-state icon="opname" title="Belum ada opname kain"
                              description="Buat opname untuk menghitung ulang sisa semua roll. Koreksi satu roll tetap bisa lewat Scan Kain > Cek & Koreksi Roll." />
        @else
            <div class="overflow-x-auto">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th>No. Opname</th>
                            <th>Cakupan</th>
                            <th class="text-right">Roll Dihitung</th>
                            <th>Status</th>
                            <th>Dibuat oleh</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($opnames as $opname)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.fabric-opnames.show', $opname) }}" class="font-mono text-sm font-semibold text-ink-950 hover:underline">{{ $opname->code }}</a>
                                    <p class="text-[11px] text-ink-400">{{ $opname->date->format('d/m/Y') }}</p>
                                </td>
                                <td class="text-sm text-ink-700">{{ $opname->scopeLabel() }}</td>
                                <td class="text-right text-sm tabular-nums">{{ number_format($opname->counted_count, 0, ',', '.') }} / {{ number_format($opname->lines_count, 0, ',', '.') }}</td>
                                <td>
                                    <x-ui.badge :variant="$opname->isDraft() ? 'warning' : 'success'">{{ \App\Models\FabricOpname::STATUSES[$opname->status] ?? $opname->status }}</x-ui.badge>
                                </td>
                                <td class="text-sm text-ink-500">{{ $opname->user?->name ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-ui.pagination :paginator="$opnames" />
        @endif
    </div>
</x-app-layout>
