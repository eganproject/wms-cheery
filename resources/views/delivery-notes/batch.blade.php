{{-- Tujuan QR surat jalan ke penjahit (tanpa login). --}}
@php
    $num = fn ($value) => number_format((int) $value, 0, ',', '.');
    $handed = (int) $batch->items->sum('quantity');
    $got = (int) $batch->items->sum(fn ($item) => min($item->quantity, (int) ($received[$item->product_id] ?? 0)));
@endphp
<x-guest-layout :title="'Surat Jalan '.$batch->code" heading="Surat Jalan ke Penjahit" :subheading="$batch->code">
    <div class="space-y-5 text-sm">
        <div class="flex items-center justify-between gap-3">
            @if ($batch->closed_at)
                <x-ui.badge variant="gray">Ditutup</x-ui.badge>
            @elseif ($batch->inWarehouse())
                <x-ui.badge variant="warning">Di Gudang</x-ui.badge>
            @elseif ($outstanding->isEmpty())
                <x-ui.badge variant="success">Selesai diterima</x-ui.badge>
            @else
                <x-ui.badge variant="info">Sedang dijahit</x-ui.badge>
            @endif
            <span class="text-xs text-ink-500">dikirim {{ ($batch->sent_at ?? $batch->date)->format('d/m/Y') }}</span>
        </div>

        <dl class="grid grid-cols-2 gap-3 rounded-lg bg-ink-50 p-3">
            <div>
                <dt class="text-[11px] text-ink-500">Penjahit</dt>
                <dd class="font-semibold text-ink-950">{{ $batch->supplier?->name ?? 'Di gudang' }}</dd>
            </div>
            <div>
                <dt class="text-[11px] text-ink-500">Dari cutting</dt>
                <dd class="font-semibold text-ink-950">{{ $batch->cutting?->code ?? 'Input manual' }}</dd>
                @if ($batch->cutting?->employee)
                    <dd class="text-xs text-ink-500">{{ $batch->cutting->employee->name }}</dd>
                @endif
            </div>
            <div>
                <dt class="text-[11px] text-ink-500">Dikirim</dt>
                <dd class="font-semibold tabular-nums text-ink-950">{{ $num($handed) }} pcs</dd>
            </div>
            <div>
                <dt class="text-[11px] text-ink-500">Sudah diterima / sisa</dt>
                <dd class="font-semibold tabular-nums text-ink-950">{{ $num($got) }} / {{ $num($outstanding->sum()) }} pcs</dd>
            </div>
        </dl>

        @if ($batch->closed_at && $batch->close_reason)
            <p class="rounded-lg bg-ink-50 px-3 py-2 text-xs text-ink-600">Ditutup: {{ $batch->close_reason }}</p>
        @endif

        <div>
            <p class="mb-2 font-semibold text-ink-950">Barang</p>
            <div class="grid grid-cols-[1fr_auto_auto_auto] gap-x-3 text-[11px] text-ink-500">
                <span>SKU</span><span class="text-right">Kirim</span><span class="text-right">Terima</span><span class="text-right">Sisa</span>
            </div>
            @foreach ($batch->items as $item)
                @php $in = min($item->quantity, (int) ($received[$item->product_id] ?? 0)); @endphp
                <div class="grid grid-cols-[1fr_auto_auto_auto] items-center gap-x-3 border-b border-ink-100 py-2 tabular-nums">
                    <div class="min-w-0">
                        <p class="font-mono text-xs font-semibold text-ink-950">{{ $item->product->sku }}</p>
                        <p class="truncate text-xs text-ink-500">{{ $item->product->name }}</p>
                    </div>
                    <span class="text-right">{{ $num($item->quantity) }}</span>
                    <span class="text-right">{{ $num($in) }}</span>
                    <span class="text-right font-semibold">{{ $num($outstanding[$item->product_id] ?? 0) }}</span>
                </div>
            @endforeach
        </div>

        <div>
            <p class="mb-2 font-semibold text-ink-950">Penerimaan barang jadi</p>
            @forelse ($batch->inbounds as $inbound)
                <div class="flex items-center justify-between gap-3 border-b border-ink-100 py-2">
                    <div>
                        <p class="font-mono text-xs font-semibold text-ink-950">{{ $inbound->code }}</p>
                        <p class="text-xs text-ink-500">{{ $inbound->date?->format('d/m/Y') }} · {{ $num($inbound->items_sum_quantity) }} pcs</p>
                    </div>
                    @if ($inbound->status === \App\Models\Inbound::STATUS_POSTED)
                        <x-ui.badge variant="success">Diterima</x-ui.badge>
                    @else
                        <x-ui.badge variant="warning">Menunggu persetujuan</x-ui.badge>
                    @endif
                </div>
            @empty
                <p class="text-ink-400">Belum ada barang jadi yang diterima.</p>
            @endforelse
        </div>

        @include('delivery-notes.partials.timeline')

        @can('fabrics.view')
            <x-ui.button :href="$batch->url()" variant="secondary" icon="external-link" class="w-full justify-center">Buka di WMS</x-ui.button>
        @endcan
        <p class="text-center text-[11px] text-ink-400">Data terkini per {{ now()->format('d/m/Y H:i') }}</p>
    </div>
</x-guest-layout>
