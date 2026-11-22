{{-- Tujuan QR surat jalan kain ke Cutting (tanpa login). --}}
@php
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');
    $num = fn ($value) => number_format((int) $value, 0, ',', '.');
    $handed = $cutting->batches->sum(fn ($batch) => $batch->items->sum('quantity'));
@endphp
<x-guest-layout :title="'Surat Jalan '.$cutting->code" heading="Surat Jalan Kain ke Cutting" :subheading="$cutting->code">
    <div class="space-y-5 text-sm">
        <div class="flex items-center justify-between gap-3">
            <x-ui.badge :variant="$cutting->isOpen() ? 'warning' : 'success'">{{ \App\Models\FabricCutting::STATUSES[$cutting->status] ?? $cutting->status }}</x-ui.badge>
            <span class="text-xs text-ink-500">mulai {{ $cutting->created_at->format('d/m/Y') }}</span>
        </div>

        <dl class="grid grid-cols-2 gap-3 rounded-lg bg-ink-50 p-3">
            <div>
                <dt class="text-[11px] text-ink-500">Pemotong</dt>
                <dd class="font-semibold text-ink-950">{{ $cutting->employee?->name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-[11px] text-ink-500">Dipotong menjadi</dt>
                <dd class="font-semibold text-ink-950">{{ $cutting->productName() }}</dd>
            </div>
        </dl>

        <div>
            <p class="mb-2 font-semibold text-ink-950">Kain diambil</p>
            @forelse ($rolls as $row)
                <div class="flex items-start justify-between gap-3 border-b border-ink-100 py-2">
                    <div class="min-w-0">
                        <p class="font-mono text-xs font-semibold text-ink-950">{{ $row['code'] }}</p>
                        <p class="truncate text-xs text-ink-500">{{ $row['fabric']->displayName() }} · {{ $row['date']?->format('d/m/Y') }}</p>
                    </div>
                    <div class="text-right tabular-nums">
                        <p class="font-semibold text-ink-950">{{ $qty($row['taken'] - $row['returned']) }} {{ $row['fabric']->unitShort() }}</p>
                        @if ($row['returned'] > 0)
                            <p class="text-[11px] text-ink-500">ambil {{ $qty($row['taken']) }}, kembali {{ $qty($row['returned']) }}</p>
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-ink-400">Belum ada kain keluar.</p>
            @endforelse
            @foreach ($totals as $total)
                <p class="mt-2 flex justify-between text-xs font-semibold text-ink-700">
                    <span>Total {{ $total['fabric']->sku }} · {{ $total['rolls'] }} roll</span>
                    <span class="tabular-nums">{{ $qty($total['taken'] - $total['returned']) }} {{ $total['fabric']->unitShort() }}</span>
                </p>
            @endforeach
        </div>

        <div>
            <p class="mb-2 font-semibold text-ink-950">Hasil potong · {{ $num($handed) }} pcs</p>
            @forelse ($cutting->batches as $batch)
                @php $left = $batch->outstanding($received->get($batch->id, collect()))->sum(); @endphp
                <div class="flex items-center justify-between gap-3 border-b border-ink-100 py-2">
                    <div>
                        <p class="font-mono text-xs font-semibold text-ink-950">{{ $batch->code }}</p>
                        <p class="text-xs text-ink-500">{{ $batch->inWarehouse() ? 'Di gudang' : $batch->supplier?->name }} · {{ $num($batch->items->sum('quantity')) }} pcs</p>
                    </div>
                    @if ($batch->closed_at)
                        <x-ui.badge variant="gray">Ditutup</x-ui.badge>
                    @elseif ($batch->inWarehouse())
                        <x-ui.badge variant="warning">Di Gudang</x-ui.badge>
                    @elseif ($left === 0)
                        <x-ui.badge variant="success">Selesai</x-ui.badge>
                    @else
                        <x-ui.badge variant="info">Dijahit · sisa {{ $num($left) }}</x-ui.badge>
                    @endif
                </div>
            @empty
                <p class="text-ink-400">Belum ada hasil potong yang dicatat.</p>
            @endforelse
        </div>

        @include('delivery-notes.partials.timeline')

        @can('fabrics.view')
            <x-ui.button :href="route('admin.fabric-cuttings.show', $cutting)" variant="secondary" icon="external-link" class="w-full justify-center">Buka di WMS</x-ui.button>
        @endcan
        <p class="text-center text-[11px] text-ink-400">Data terkini per {{ now()->format('d/m/Y H:i') }}</p>
    </div>
</x-guest-layout>
