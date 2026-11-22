@php
    use App\Models\FabricMovement;
    $canIssue = auth()->user()->can('fabrics.issue');
    $canAdjust = auth()->user()->can('fabrics.adjust');
    $totalHanded = (int) $handedByVariant->sum();
    $inWarehouse = (int) $cutting->batches->filter(fn ($batch) => $batch->inWarehouse() && ! $batch->closed_at)->sum(fn ($batch) => $batch->items->sum('quantity'));
@endphp

<x-app-layout :title="$cutting->code">
    <x-ui.page-header :title="'Cutting '.$cutting->code"
                      :subtitle="$cutting->productName().' · pemotong '.($cutting->employee?->name ?? '—').' · mulai '.$cutting->created_at->format('d/m/Y H:i')"
                      :back="route('admin.fabric-cuttings.index')"
                      :status="\App\Models\FabricCutting::STATUSES[$cutting->status] ?? null"
                      :statusVariant="$cutting->isOpen() ? 'warning' : 'success'">
        <x-slot name="actions">
            @if ($canIssue && $cutting->isOpen())
                <x-ui.button :href="route('admin.fabric-scan')" variant="secondary" icon="scan">Tambah Kain (Scan)</x-ui.button>
            @endif
        </x-slot>
    </x-ui.page-header>

    <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
        @foreach ($fabricRows->take(2) as $row)
            <x-ui.stat-card :label="$row['fabric']->sku" :value="$row['fabric']->format($row['quantity'])" icon="layers" :accent="$loop->first"
                            :hint="$row['estimate'] !== null ? '≈ '.$row['estimate'].' pcs menurut BOM' : 'BOM kain belum diatur'" />
        @endforeach
        <x-ui.stat-card label="Hasil Potong" :value="number_format($totalHanded, 0, ',', '.').' pcs'" icon="truck"
                        :hint="$cutting->batches->count().' batch'.($inWarehouse ? ' · '.number_format($inWarehouse, 0, ',', '.').' pcs di gudang' : '')" />
        <x-ui.stat-card label="Varian" :value="$variants->count()" icon="box" :hint="$cutting->product_group_id ? 'Semua varian '.$cutting->productName() : $cutting->product->sku" />
    </div>

    <div class="mt-5 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Batch WIP Jahit --}}
            <x-ui.card title="Hasil Potong" subtitle="Setiap pencatatan jadi satu batch: disimpan di gudang atau langsung di penjahit (WIP Jahit). Berkurang otomatis saat barang jadi diterima lewat Penerimaan Barang." padding="p-0">
                @if ($cutting->batches->isEmpty())
                    <x-ui.empty-state icon="truck" title="Belum ada hasil potong"
                                      description="Catat hasil potong per varian di kolom kanan: simpan di gudang atau langsung ke penjahit." />
                @else
                    <div class="divide-y divide-ink-100">
                        @foreach ($cutting->batches as $batch)
                            @php
                                $got = $received->get($batch->id, collect());
                                $left = $batch->outstanding($got);
                            @endphp
                            <div id="{{ $batch->code }}" class="px-5 py-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p class="font-mono text-sm font-semibold text-ink-950">{{ $batch->code }}</p>
                                        @if ($batch->inWarehouse())
                                            <p class="text-sm text-amber-700">Disimpan di gudang · dipotong {{ $batch->date->format('d/m/Y') }}</p>
                                        @else
                                            <p class="text-sm text-ink-700">{{ $batch->supplier->name }} · dikirim {{ ($batch->sent_at ?? $batch->date)->format('d/m/Y') }}</p>
                                        @endif
                                        @if ($batch->note)
                                            <p class="text-[11px] text-ink-400">{{ $batch->note }}</p>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-2">
                                        @if ($batch->closed_at)
                                            <x-ui.badge variant="gray">Ditutup: {{ $batch->close_reason }}</x-ui.badge>
                                        @elseif ($left->isEmpty())
                                            <x-ui.badge variant="success">Selesai</x-ui.badge>
                                        @else
                                            @if ($batch->inWarehouse())
                                                <x-ui.badge variant="warning">Di Gudang · {{ $left->sum() }} pcs</x-ui.badge>
                                                @if ($canIssue && $tailors->isNotEmpty())
                                                    <div x-data="{ open: false }" class="relative">
                                                        <x-ui.button type="button" size="sm" icon="truck" x-on:click="open = ! open">Kirim ke Penjahit</x-ui.button>
                                                        <form x-show="open" x-cloak x-on:click.outside="open = false" method="POST" action="{{ route('admin.sewing-batches.send', $batch) }}"
                                                              class="absolute right-0 z-20 mt-1 w-80 space-y-3 rounded-xl bg-white p-3 text-left shadow-lg ring-1 ring-ink-100">
                                                            @csrf
                                                            <select name="supplier_id" required class="form-control w-full" data-native>
                                                                <option value="">Pilih penjahit…</option>
                                                                @foreach ($tailors as $tailor)
                                                                    <option value="{{ $tailor->id }}">{{ $tailor->name }}</option>
                                                                @endforeach
                                                            </select>
                                                            <input type="date" name="date" value="{{ now()->format('Y-m-d') }}" required class="form-control w-full">
                                                            <div class="space-y-1.5">
                                                                <p class="text-[11px] text-ink-500">Jumlah dikirim (boleh sebagian, sisanya tetap di gudang)</p>
                                                                @foreach ($batch->items as $item)
                                                                    <label class="flex items-center gap-2 text-xs">
                                                                        <span class="min-w-0 flex-1 truncate"><span class="font-mono font-semibold">{{ $item->product->sku }}</span> · ada {{ $item->quantity }}</span>
                                                                        <input type="number" name="items[{{ $item->product_id }}]" value="{{ $item->quantity }}" min="0" max="{{ $item->quantity }}" step="1"
                                                                               class="form-control h-9 w-20 text-right tabular-nums">
                                                                    </label>
                                                                @endforeach
                                                            </div>
                                                            <x-ui.button type="submit" size="sm" icon="truck" class="w-full">Kirim</x-ui.button>
                                                        </form>
                                                    </div>
                                                @endif
                                            @else
                                                <x-ui.badge variant="info">WIP Jahit · sisa {{ $left->sum() }} pcs</x-ui.badge>
                                            @endif
                                            @if ($canAdjust)
                                                <div x-data="{ open: false }" class="relative">
                                                    <x-ui.button type="button" variant="ghost" size="sm" x-on:click="open = ! open">Tutup Batch</x-ui.button>
                                                    <form x-show="open" x-cloak x-on:click.outside="open = false" method="POST" action="{{ route('admin.sewing-batches.close', $batch) }}"
                                                          class="absolute right-0 z-20 mt-1 w-72 space-y-2 rounded-xl bg-white p-3 shadow-lg ring-1 ring-ink-100">
                                                        @csrf
                                                        <p class="text-xs text-ink-600">Sisa {{ $left->sum() }} pcs tidak akan ditunggu lagi.</p>
                                                        <input type="text" name="reason" required maxlength="255" placeholder="Alasan, mis. 2 pcs rusak di penjahit" class="form-control h-10 text-sm">
                                                        <x-ui.button type="submit" size="sm" class="w-full">Tutup Batch</x-ui.button>
                                                    </form>
                                                </div>
                                            @endif
                                        @endif
                                        @if ($canIssue && $batch->inbounds->isEmpty())
                                            <x-ui.confirm-delete :action="route('admin.sewing-batches.destroy', $batch)" title="Hapus batch ini?"
                                                                 :description="$batch->code.' belum dipakai di penerimaan barang dan akan dihapus.'" />
                                        @endif
                                    </div>
                                </div>
                                <table class="mt-3 w-full text-sm">
                                    <thead>
                                        <tr class="text-left text-[11px] uppercase tracking-wide text-ink-400">
                                            <th class="py-1 font-medium">Varian</th>
                                            <th class="py-1 text-right font-medium">Pcs</th>
                                            <th class="py-1 text-right font-medium">Diterima</th>
                                            <th class="py-1 text-right font-medium">Sisa</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($batch->items as $item)
                                            <tr class="border-t border-ink-50">
                                                <td class="py-1.5"><span class="font-mono text-xs font-semibold">{{ $item->product->sku }}</span> <span class="text-ink-600">{{ $item->product->name }}</span></td>
                                                <td class="py-1.5 text-right tabular-nums">{{ $item->quantity }}</td>
                                                <td class="py-1.5 text-right tabular-nums">{{ (int) ($got[$item->product_id] ?? 0) }}</td>
                                                <td class="py-1.5 text-right font-semibold tabular-nums">{{ $batch->closed_at ? '—' : max(0, $item->quantity - (int) ($got[$item->product_id] ?? 0)) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                                @if ($batch->inbounds->isNotEmpty())
                                    <p class="mt-2 text-[11px] text-ink-500">Penerimaan:
                                        @foreach ($batch->inbounds as $inbound)
                                            <a href="{{ route('admin.inbounds.show', $inbound) }}" class="font-mono underline underline-offset-2">{{ $inbound->code }}</a>{{ $inbound->isPosted() ? '' : ' (belum disetujui)' }}@if (! $loop->last), @endif
                                        @endforeach
                                    </p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>

            {{-- Kain yang dipakai --}}
            <x-ui.card title="Kain di Cutting Ini" subtitle="Kain keluar lewat scan untuk cutting ini, termasuk yang dibatalkan dan sisa yang dikembalikan." padding="p-0">
                <div class="overflow-x-auto">
                    <table class="erp-table">
                        <thead>
                            <tr>
                                <th>Waktu</th>
                                <th>Roll</th>
                                <th>Jenis</th>
                                <th class="text-right">Jumlah</th>
                                <th>Oleh</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($cutting->movements as $movement)
                                <tr>
                                    <td class="whitespace-nowrap text-sm text-ink-600">{{ $movement->moved_at->format('d/m/Y H:i') }}</td>
                                    <td class="text-sm">
                                        <span class="font-mono font-semibold">{{ $movement->roll->code }}</span>
                                        <span class="block text-[11px] text-ink-400">{{ $movement->roll->fabric->displayName() }}</span>
                                    </td>
                                    <td class="text-sm text-ink-600">{{ FabricMovement::TYPES[$movement->type] ?? $movement->type }}{{ $movement->isCancelled() ? ' (dibatalkan)' : '' }}</td>
                                    <td class="text-right text-sm font-semibold tabular-nums {{ $movement->quantity < 0 ? 'text-rose-600' : 'text-green-700' }}">
                                        {{ $movement->quantity > 0 ? '+' : '' }}{{ $movement->roll->fabric->format($movement->quantity) }}
                                    </td>
                                    <td class="text-sm text-ink-500">{{ $movement->user?->name ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        </div>

        <div class="space-y-6">
            @if ($canIssue)
                <x-ui.card title="Catat Hasil Potong" subtitle="Isi hasil potong per varian. Simpan dulu di gudang atau langsung serahkan ke penjahit; bisa bertahap.">
                    <form method="POST" action="{{ route('admin.fabric-cuttings.handover', $cutting) }}" class="space-y-4"
                          x-data="{ tujuan: @js(old('tujuan', $tailors->isEmpty() ? 'gudang' : 'penjahit')) }">
                        @csrf
                        <div class="grid grid-cols-2 gap-1 rounded-xl bg-control p-1">
                            <label class="cursor-pointer rounded-lg px-3 py-2 text-center text-sm font-semibold"
                                   :class="tujuan === 'gudang' ? 'bg-white text-ink-950 shadow-sm' : 'text-ink-500'">
                                <input type="radio" name="tujuan" value="gudang" x-model="tujuan" class="sr-only"> Simpan di Gudang
                            </label>
                            <label class="cursor-pointer rounded-lg px-3 py-2 text-center text-sm font-semibold"
                                   :class="tujuan === 'penjahit' ? 'bg-white text-ink-950 shadow-sm' : 'text-ink-500'">
                                <input type="radio" name="tujuan" value="penjahit" x-model="tujuan" class="sr-only"> Ke Penjahit
                            </label>
                        </div>

                        <div x-show="tujuan === 'penjahit'" x-cloak>
                            @if ($tailors->isEmpty())
                                <p class="rounded-lg bg-amber-50 px-3 py-2.5 text-sm text-amber-800">
                                    Belum ada pemasok bertanda <b>Penjahit/Konveksi</b>. Centang di data
                                    <a href="{{ route('admin.suppliers.index') }}" class="underline underline-offset-2">Pemasok</a> terlebih dahulu.
                                </p>
                            @else
                                <x-ui.field label="Penjahit" for="supplier_id" :error="$errors->get('supplier_id')" required>
                                    <x-ui.select id="supplier_id" name="supplier_id">
                                        <option value="">Pilih penjahit…</option>
                                        @foreach ($tailors as $tailor)
                                            <option value="{{ $tailor->id }}" @selected((int) old('supplier_id') === $tailor->id)>{{ $tailor->name }}</option>
                                        @endforeach
                                    </x-ui.select>
                                </x-ui.field>
                            @endif
                        </div>

                        <x-ui.field label="Tanggal" for="date" :error="$errors->get('date')" required>
                            <x-text-input id="date" name="date" type="date" :value="old('date', now()->format('Y-m-d'))" required />
                        </x-ui.field>

                        <div>
                            <p class="form-label">Jumlah pcs per varian</p>
                            <div class="space-y-2">
                                @foreach ($variants as $variant)
                                    <label class="flex items-center gap-3">
                                        <span class="min-w-0 flex-1">
                                            <span class="block font-mono text-xs font-semibold text-ink-800">{{ $variant->sku }}</span>
                                            <span class="block truncate text-[11px] text-ink-500">{{ $variant->name }}{{ $handedByVariant->get($variant->id) ? ' · sudah '.$handedByVariant[$variant->id] : '' }}</span>
                                        </span>
                                        <input type="number" name="items[{{ $variant->id }}]" min="0" step="1" inputmode="numeric"
                                               value="{{ old('items.'.$variant->id) }}" placeholder="0"
                                               class="form-control h-10 w-24 text-right tabular-nums">
                                    </label>
                                @endforeach
                            </div>
                            <x-input-error :messages="$errors->get('items')" class="mt-2" />
                        </div>

                        <x-ui.field label="Catatan" for="note">
                            <x-text-input id="note" name="note" type="text" :value="old('note')" placeholder="Opsional" />
                        </x-ui.field>

                        <x-ui.button type="submit" icon="check" class="w-full" x-text="tujuan === 'gudang' ? 'Simpan di Gudang' : 'Serahkan ke Penjahit'">Simpan</x-ui.button>
                    </form>
            </x-ui.card>

                @if ($cutting->isOpen())
                    <x-ui.card title="Selesai Cutting" subtitle="Tandai bila semua kain sudah dipotong. Sisa kain (bila ada) dikembalikan ke rollnya.">
                        <form method="POST" action="{{ route('admin.fabric-cuttings.finish', $cutting) }}" class="space-y-3">
                            @csrf
                            @foreach ($rolls as $row)
                                <label class="flex items-center gap-3">
                                    <span class="min-w-0 flex-1">
                                        <span class="block font-mono text-xs font-semibold text-ink-800">{{ $row['roll']->code }}</span>
                                        <span class="block text-[11px] text-ink-500">diambil {{ $row['roll']->fabric->format($row['taken']) }}</span>
                                    </span>
                                    <span class="relative">
                                        <input type="number" name="returns[{{ $row['roll']->id }}]" min="0" step="0.01" inputmode="decimal"
                                               value="{{ old('returns.'.$row['roll']->id) }}" placeholder="Sisa 0"
                                               class="form-control h-10 w-28 pr-9 text-right tabular-nums">
                                        <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs text-ink-400">{{ $row['roll']->fabric->unitShort() }}</span>
                                    </span>
                                </label>
                            @endforeach
                            <x-input-error :messages="collect($errors->get('returns*'))->flatten()->all()" />
                            <x-ui.button type="submit" variant="secondary" icon="check" class="w-full">Selesai Cutting</x-ui.button>
                        </form>
                    </x-ui.card>
                @endif
            @endif
        </div>
    </div>
</x-app-layout>
