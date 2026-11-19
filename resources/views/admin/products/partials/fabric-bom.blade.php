{{--
    BOM kain barang (opsional): kain apa dan berapa banyak untuk 1 unit.
    Sama dengan Pemakaian Kain per Barang di detail kain; dipakai menghitung
    Kebutuhan Kain dan menjadi pilihan cepat saat scan kain keluar.

    Variabel: $product, $fabricBom (FabricUsage + fabric), $fabricCatalog.
--}}
@php
    $canManage = auth()->user()->can('fabrics.manage');
    $variantCount = $product->group?->variants?->count() ?? 0;
    $initial = collect(old('fabrics', $fabricBom->map(fn ($usage) => [
        'fabric_id' => $usage->fabric_id,
        'quantity' => rtrim(rtrim(number_format($usage->quantity, 3, '.', ''), '0'), '.'),
    ])->all()))->values();
@endphp

<x-ui.card title="BOM Kain"
           subtitle="Opsional. Kain yang dipakai untuk membuat 1 {{ $product->unit }} barang ini; dipakai menghitung Kebutuhan Kain.">
    @if (! $canManage)
        @if ($fabricBom->isEmpty())
            <p class="text-sm text-ink-400">Belum ada BOM kain.</p>
        @else
            <ul class="space-y-3">
                @foreach ($fabricBom as $usage)
                    <li class="flex items-center gap-3">
                        <x-ui.fabric-image :fabric="$usage->fabric" size="sm" />
                        <a href="{{ route('admin.fabrics.show', $usage->fabric) }}" class="min-w-0 flex-1 text-sm hover:underline">
                            <span class="block font-mono text-[11px] text-ink-500">{{ $usage->fabric->sku }}</span>
                            <span class="block truncate text-ink-950">{{ $usage->fabric->displayName() }}</span>
                        </a>
                        <span class="shrink-0 text-sm font-semibold tabular-nums">{{ $usage->fabric->format($usage->quantity) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    @else
        <form method="POST" action="{{ route('admin.products.fabrics.update', $product) }}"
              x-data="{
                  catalog: {{ Js::from($fabricCatalog) }},
                  rows: {{ Js::from($initial) }},
                  fabric(id) { return this.catalog.find((item) => String(item.id) === String(id)) },
                  available(row) { return this.catalog.filter((item) => String(item.id) === String(row.fabric_id) || ! this.rows.some((other) => String(other.fabric_id) === String(item.id))) },
                  add() { this.rows.push({ fabric_id: '', quantity: '' }) },
              }" class="space-y-3">
            @csrf
            @method('PUT')

            <template x-if="rows.length === 0">
                <p class="rounded-lg bg-ink-50 px-3 py-3 text-sm text-ink-500">Belum ada BOM kain. Barang tetap bisa dipakai seperti biasa.</p>
            </template>

            <template x-for="(row, index) in rows" :key="index">
                <div class="flex items-start gap-2 rounded-lg border border-ink-100 p-2">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-ink-50 text-ink-300 ring-1 ring-inset ring-ink-100">
                        <template x-if="fabric(row.fabric_id)?.image">
                            <img :src="fabric(row.fabric_id).image" alt="" class="h-full w-full object-cover">
                        </template>
                        <x-icon name="layers" class="h-4 w-4" x-show="! fabric(row.fabric_id)?.image" />
                    </span>
                    <div class="min-w-0 flex-1 space-y-2">
                    <select class="form-control w-full" :name="`fabrics[${index}][fabric_id]`" x-model="row.fabric_id" required>
                        <option value="">Pilih kain…</option>
                        <template x-for="item in available(row)" :key="item.id">
                            <option :value="item.id" :selected="String(item.id) === String(row.fabric_id)" x-text="`${item.sku} — ${item.name}`"></option>
                        </template>
                    </select>
                    <div class="relative w-36">
                        <input type="number" step="0.001" min="0.001" inputmode="decimal" required placeholder="0"
                               class="form-control pr-16 text-right tabular-nums" :name="`fabrics[${index}][quantity]`" x-model="row.quantity">
                        <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs text-ink-400" x-text="fabric(row.fabric_id)?.unit ? `${fabric(row.fabric_id).unit}/{{ $product->unit }}` : ''"></span>
                    </div>
                    </div>
                    <button type="button" x-on:click="rows.splice(index, 1)" title="Hapus kain"
                            class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-ink-400 hover:bg-ink-100 hover:text-rose-600">
                        <x-icon name="trash" class="h-4 w-4" />
                    </button>
                </div>
            </template>

            <x-input-error :messages="collect($errors->get('fabrics*'))->flatten()->all()" />

            <div class="flex flex-wrap items-center gap-3 border-t border-ink-100 pt-3">
                <x-ui.button type="button" variant="secondary" size="sm" icon="plus" x-on:click="add()"
                             x-bind:disabled="rows.length >= catalog.length">Tambah Kain</x-ui.button>
                @if ($fabricCatalog->isEmpty())
                    <a href="{{ route('admin.fabrics.create') }}" class="text-xs text-ink-500 underline underline-offset-4">Belum ada data kain, tambah dulu</a>
                @endif
                @if ($variantCount > 1)
                    <label class="flex items-center gap-2 text-xs text-ink-600">
                        <input type="checkbox" name="variants" value="1" class="rounded border-ink-300">
                        Terapkan ke semua {{ $variantCount }} varian
                    </label>
                @endif
                <x-ui.button type="submit" size="sm" icon="check" class="ml-auto">Simpan BOM</x-ui.button>
            </div>
        </form>
    @endif
</x-ui.card>
