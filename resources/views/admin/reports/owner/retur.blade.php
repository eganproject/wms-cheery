@php
    $t = $data['totals'];
    $maxReason = max(1, $data['reasons']->max('receipts') ?? 1);
@endphp

<div class="grid grid-cols-2 gap-4 xl:grid-cols-5">
    <x-ui.stat-card label="Persentase Retur" :value="$pct($t['rate'])" icon="return" accent
                    info="Unit yang diretur dibagi unit terjual di marketplace pada periode yang sama."
                    :hint="$n($t['units']).' dari '.$n($t['sold']).' unit terjual'" />
    <x-ui.stat-card label="Dokumen Retur" :value="$n($t['receipts'])" icon="document"
                    :hint="$n($t['marketplace']).' dari marketplace'" info="Penerimaan retur yang sudah disetujui, menurut tanggal dokumennya." />
    <x-ui.stat-card label="Kembali Baik" :value="$n($t['good'])" icon="check-circle" hint="Unit masuk lagi ke stok jual" />
    <x-ui.stat-card label="Rusak" :value="$n($t['damaged'])" icon="damaged" hint="Unit masuk stok rusak" />
    <x-ui.stat-card label="Barang Asing" :value="$n($t['foreign_units'])" icon="warning"
                    :hint="$n($t['foreign_receipts']).' paket berisi barang bukan milik kita'" />
</div>

<div class="mt-5 grid gap-5 xl:grid-cols-3">
    <div class="frappe-card p-4">
        <p class="mb-3 text-sm font-medium text-ink-950">Alasan Retur</p>
        @forelse ($data['reasons'] as $row)
            <div class="mb-2.5">
                <div class="flex justify-between text-sm">
                    <span class="text-ink-700">{{ $row->reason }}</span>
                    <span class="font-semibold tabular-nums">{{ $n($row->receipts) }}</span>
                </div>
                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-ink-100">
                    <div class="h-full rounded-full bg-brand-500" style="width: {{ round($row->receipts / $maxReason * 100) }}%"></div>
                </div>
            </div>
        @empty
            <p class="py-6 text-center text-sm text-ink-400">Belum ada retur pada periode ini.</p>
        @endforelse
    </div>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white xl:col-span-2">
        <p class="border-b border-ink-100 px-4 py-3 text-sm font-medium text-ink-950">Retur per Barang</p>
        @if ($data['products']->isEmpty())
            <x-ui.empty-state icon="return" title="Belum ada retur" description="Tidak ada retur yang disetujui pada periode ini." />
        @else
            <div class="overflow-x-auto">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th>Barang</th>
                            <th class="px-3 text-right"><x-ui.sort key="units" first="desc">Retur</x-ui.sort></th>
                            <th class="px-3 text-right">Baik</th>
                            <th class="px-3 text-right"><x-ui.sort key="damaged" first="desc">Rusak</x-ui.sort></th>
                            <th class="px-3 text-right">Terjual</th>
                            <th class="px-3 text-right"><span class="inline-flex items-center gap-1"><x-ui.sort key="rate" first="desc">% Retur</x-ui.sort> <x-ui.hint text="Unit retur dibagi unit terjual pada periode yang sama. Kosong bila barangnya tidak terjual pada periode ini." /></span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($data['products'] as $row)
                            <tr>
                                <td class="text-sm">
                                    <a href="{{ route('admin.products.show', $row->product) }}" class="font-medium text-ink-950 underline-offset-4 hover:underline">{{ $row->product->name }}</a>
                                    <p class="font-mono text-xs text-ink-500">{{ $row->product->sku }}</p>
                                </td>
                                <td class="px-3 text-right text-sm font-semibold tabular-nums">{{ $n($row->units) }}</td>
                                <td class="px-3 text-right text-sm tabular-nums text-emerald-700">{{ $row->good ? $n($row->good) : '—' }}</td>
                                <td class="px-3 text-right text-sm tabular-nums {{ $row->damaged ? 'text-red-600' : 'text-ink-300' }}">{{ $row->damaged ? $n($row->damaged) : '—' }}</td>
                                <td class="px-3 text-right text-sm tabular-nums text-ink-600">{{ $n($row->sold) }}</td>
                                <td class="px-3 text-right text-sm font-semibold tabular-nums {{ ($row->rate ?? 0) >= 10 ? 'text-red-600' : 'text-ink-950' }}">{{ $pct($row->rate) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-ui.pagination :paginator="$data['products']" unit="barang" />
        @endif
    </div>
</div>
