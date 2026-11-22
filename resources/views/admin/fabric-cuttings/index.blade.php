{{--
    Cutting & Jahit: kain yang sedang dipotong (Cutting) dan hasil potong
    yang sedang dijahit di penjahit (WIP Jahit).
--}}
<x-app-layout title="Cutting & Jahit">
    @include('admin.fabric-cuttings.partials.header')

    <form method="GET" action="{{ route('admin.fabric-cuttings.index') }}" data-auto-submit
          class="mb-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 lg:flex-row lg:items-center">
        @if ($view === 'jahit')
            <input type="hidden" name="lihat" value="jahit">
        @endif
        <div class="relative flex-1">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-8 items-center justify-center text-ink-500">
                <x-icon name="search" class="h-4 w-4" />
            </span>
            <x-text-input type="search" name="search" :value="request('search')"
                          :placeholder="$view === 'jahit' ? 'Cari no. batch, no. cutting, atau SKU barang...' : 'Cari no. cutting, barang, atau pemotong...'" class="pl-8" />
        </div>

        @if ($view === 'jahit')
            <x-ui.select name="supplier_id" class="lg:w-56">
                <option value="">Semua penjahit</option>
                @foreach ($tailors as $tailor)
                    <option value="{{ $tailor->id }}" @selected((int) request('supplier_id') === $tailor->id)>{{ $tailor->name }}</option>
                @endforeach
            </x-ui.select>
        @endif

        <x-ui.select name="status" class="lg:w-44">
            <option value="terbuka" @selected($status === 'terbuka')>{{ $view === 'jahit' ? 'Belum selesai' : 'Sedang cutting' }}</option>
            @if ($view === 'jahit')
                <option value="gudang" @selected($status === 'gudang')>Di gudang</option>
                <option value="penjahit" @selected($status === 'penjahit')>Di penjahit</option>
            @endif
            <option value="selesai" @selected($status === 'selesai')>Selesai</option>
            <option value="semua" @selected($status === 'semua')>Semua</option>
        </x-ui.select>

        <x-ui.button type="submit" variant="secondary" icon="filter" class="lg:flex-none">Terapkan</x-ui.button>
    </form>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white">
        @if ($view === 'cutting')
            @if ($cuttings->isEmpty())
                <x-ui.empty-state icon="clipboard" title="Belum ada cutting"
                                  description="Cutting dibuat otomatis saat kain di-scan keluar untuk sebuah barang dan pemotong." />
            @else
                <div class="overflow-x-auto">
                    <table class="erp-table">
                        <thead>
                            <tr>
                                <th>No. Cutting</th>
                                <th>Barang</th>
                                <th>Pemotong</th>
                                <th class="text-right">Kain</th>
                                <th class="text-right">Diserahkan</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($cuttings as $cutting)
                                @php
                                    $fabrics = $cutting->movements->pluck('fabric_id')->unique()->count();
                                @endphp
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.fabric-cuttings.show', $cutting) }}" class="font-mono text-sm font-semibold text-ink-950 hover:underline">{{ $cutting->code }}</a>
                                        <p class="text-[11px] text-ink-400">{{ $cutting->created_at->format('d/m/Y H:i') }}</p>
                                    </td>
                                    <td class="text-sm">
                                        <div class="flex items-center gap-2">
                                            <x-ui.product-image :product="$cutting->product" size="xs" />
                                            <span class="min-w-0">
                                                <span class="block truncate font-medium text-ink-950">{{ $cutting->productName() }}</span>
                                                <span class="block font-mono text-[11px] text-ink-400">{{ $cutting->product->sku }}{{ $cutting->product_group_id ? ' · semua varian' : '' }}</span>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="text-sm text-ink-700">{{ $cutting->employee?->name ?? '—' }}</td>
                                    <td class="text-right text-sm tabular-nums">
                                        {{ $cutting->movements->where('type', \App\Models\FabricMovement::TYPE_OUT)->pluck('fabric_roll_id')->unique()->count() }} roll
                                        <p class="text-[11px] text-ink-400">{{ $fabrics }} jenis kain</p>
                                    </td>
                                    <td class="text-right text-sm font-semibold tabular-nums">
                                        {{ number_format($cutting->handedQuantity(), 0, ',', '.') }} pcs
                                        <p class="text-[11px] font-normal text-ink-400">{{ $cutting->batches->count() }} batch</p>
                                    </td>
                                    <td>
                                        <x-ui.badge :variant="$cutting->isOpen() ? 'warning' : 'success'">{{ \App\Models\FabricCutting::STATUSES[$cutting->status] ?? $cutting->status }}</x-ui.badge>
                                    </td>
                                    <td class="text-right">
                                        <a href="{{ route('admin.fabric-cuttings.show', $cutting) }}" title="Detail"
                                           class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                            <x-icon name="eye" class="h-4 w-4" />
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <x-ui.pagination :paginator="$cuttings" />
            @endif
        @else
            @if ($batches->isEmpty())
                <x-ui.empty-state icon="truck" title="Tidak ada batch hasil potong"
                                  description="Batch dibuat dari detail Cutting lewat Catat Hasil Potong." />
            @else
                <div class="overflow-x-auto">
                    <table class="erp-table">
                        <thead>
                            <tr>
                                <th>No. Batch</th>
                                <th>Posisi</th>
                                <th>Barang</th>
                                <th class="text-right">Diserahkan</th>
                                <th class="text-right">Diterima</th>
                                <th class="text-right">Sisa</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($batches as $batch)
                                @php
                                    $handed = (int) $batch->items->sum('quantity');
                                    $got = (int) $batch->received_map->sum();
                                @endphp
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.fabric-cuttings.show', $batch->cutting) }}#{{ $batch->code }}" class="font-mono text-sm font-semibold text-ink-950 hover:underline">{{ $batch->code }}</a>
                                        <p class="text-[11px] text-ink-400">{{ $batch->date->format('d/m/Y') }} · {{ $batch->cutting->code }}</p>
                                    </td>
                                    <td class="text-sm">
                                        @if ($batch->inWarehouse())
                                            <span class="font-medium text-amber-700">Gudang</span>
                                            <p class="text-[11px] text-ink-400">dipotong {{ $batch->date->format('d/m/Y') }}</p>
                                        @else
                                            <span class="font-medium text-ink-950">{{ $batch->supplier->name }}</span>
                                            <p class="text-[11px] text-ink-400">dikirim {{ ($batch->sent_at ?? $batch->date)->format('d/m/Y') }}</p>
                                        @endif
                                    </td>
                                    <td class="text-sm">
                                        <span class="block font-medium text-ink-950">{{ $batch->cutting->productName() }}</span>
                                        <span class="block text-[11px] text-ink-500">
                                            {{ $batch->items->map(fn ($item) => $item->product->sku.' '.$item->quantity)->implode(' · ') }}
                                        </span>
                                    </td>
                                    <td class="text-right text-sm tabular-nums">{{ number_format($handed, 0, ',', '.') }}</td>
                                    <td class="text-right text-sm tabular-nums">{{ number_format($got, 0, ',', '.') }}</td>
                                    <td class="text-right text-sm font-semibold tabular-nums">{{ number_format($batch->outstanding_total, 0, ',', '.') }}</td>
                                    <td>
                                        @if ($batch->closed_at)
                                            <x-ui.badge variant="gray" title="{{ $batch->close_reason }}">Ditutup</x-ui.badge>
                                        @elseif ($batch->inWarehouse())
                                            <x-ui.badge variant="warning">Di Gudang</x-ui.badge>
                                        @elseif ($batch->outstanding_total === 0)
                                            <x-ui.badge variant="success">Selesai</x-ui.badge>
                                        @else
                                            <x-ui.badge variant="info">WIP Jahit</x-ui.badge>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    </div>
</x-app-layout>
