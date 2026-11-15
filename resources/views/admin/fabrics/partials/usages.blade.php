{{--
    Pemakaian kain per barang: berapa banyak kain ini untuk 1 unit barang.
    Dipakai Kebutuhan Kain (harus diproduksi × pemakaian).
--}}
@php
    $initial = $usages->mapWithKeys(fn ($usage) => [$usage->product_id => $usage->quantity])->all();
    $groups = $catalog->whereNotNull('group_id')->groupBy('group_id')
        ->map(fn ($items) => ['id' => $items->first()['group_id'], 'name' => $items->first()['group'], 'count' => $items->count()])
        ->sortBy('name')->values();
    $unit = $fabric->unitShort();
@endphp

@can('fabrics.manage')
    <form method="POST" action="{{ route('admin.fabrics.usages', $fabric) }}">
        @csrf
        @method('PUT')

        <div x-data="stockPicker({{ Js::from($catalog) }}, {{ Js::from($initial) }})" class="frappe-card">
            <div class="border-b border-ink-100 px-4 py-3 sm:px-5">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <div>
                        <h2 class="text-base font-semibold text-ink-950">Pemakaian Kain per Barang</h2>
                        <p class="mt-0.5 text-xs text-ink-500">Isi berapa {{ $fabric->unit }} kain ini untuk membuat 1 unit barang. Dipakai menghitung Kebutuhan Kain.</p>
                    </div>
                    <p class="text-xs text-ink-500"><span class="font-medium text-ink-950" x-text="picked.length"></span> barang</p>
                </div>

                <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                    <div class="relative flex-1" x-on:click.outside="open = false">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-ink-400">
                            <x-icon name="search" class="h-4 w-4" />
                        </div>
                        <input type="search" x-ref="search" x-model="term" autocomplete="off"
                               placeholder="Cari SKU atau nama barang…"
                               x-on:input="search()" x-on:focus="open = true"
                               x-on:keydown.arrow-down.prevent="move(1)"
                               x-on:keydown.arrow-up.prevent="move(-1)"
                               x-on:keydown.enter.prevent="pickHighlighted()"
                               x-on:keydown.escape.prevent="open = false"
                               class="h-11 w-full rounded-lg pl-10 pr-4 text-sm text-ink-950">

                        <div x-show="open" x-cloak
                             class="absolute z-20 mt-1.5 w-full overflow-hidden rounded-xl border border-ink-100 bg-white shadow-lift">
                            <template x-if="noMatch">
                                <p class="px-4 py-3.5 text-sm text-ink-400">Tidak ada barang yang cocok.</p>
                            </template>
                            <ul class="max-h-72 divide-y divide-ink-50 overflow-y-auto">
                                <template x-for="(item, index) in matches" :key="item.id">
                                    <li>
                                        <button type="button" x-on:click="choose(item)" x-on:mouseenter="highlight = index"
                                                class="flex w-full items-center gap-3 px-4 py-2.5 text-left transition"
                                                :class="index === highlight ? 'bg-ink-50' : 'hover:bg-ink-50/60'">
                                            <div class="min-w-0 flex-1">
                                                <p class="truncate text-sm font-medium text-ink-950" x-text="item.name"></p>
                                                <p class="font-mono text-[11px] text-ink-500" x-text="item.sku"></p>
                                            </div>
                                            <template x-if="isPicked(item.id)">
                                                <span class="text-[11px] font-medium text-ink-400">sudah dipilih</span>
                                            </template>
                                        </button>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </div>

                    @if ($groups->isNotEmpty())
                        <div x-data="{ group: '' }" class="sm:w-72">
                            <x-ui.select x-model="group" class="h-11"
                                         x-on:change="catalog.filter((item) => String(item.group_id) === String(group)).forEach((item) => { if (! isPicked(item.id)) picked.push({ id: String(item.id), quantity: '' }) }); group = ''">
                                <option value="">+ Semua varian dari induk…</option>
                                @foreach ($groups as $group)
                                    <option value="{{ $group['id'] }}">{{ $group['name'] }} ({{ $group['count'] }} varian)</option>
                                @endforeach
                            </x-ui.select>
                        </div>
                    @endif
                </div>
            </div>

            <template x-if="picked.length === 0">
                <p class="px-5 py-8 text-center text-sm text-ink-400">Belum ada barang. Cari barang di atas lalu isi pemakaian kainnya.</p>
            </template>

            <ul class="divide-y divide-ink-50">
                <template x-for="row in picked" :key="row.id">
                    <li class="flex items-center gap-3 px-4 py-2.5 sm:px-5" :class="isFlashed(row.id) && 'bg-ink-50'">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-ink-950" x-text="product(row.id).name"></p>
                            <p class="font-mono text-[11px] text-ink-500" x-text="product(row.id).sku"></p>
                        </div>
                        <div class="flex items-center gap-2">
                            <input type="number" step="0.001" min="0.001" required inputmode="decimal"
                                   :data-quantity="row.id" :name="`usages[${row.id}]`" x-model="row.quantity"
                                   x-on:keydown.enter.prevent="backToSearch()"
                                   class="h-10 w-28 rounded-lg text-right text-sm font-semibold tabular-nums" placeholder="0">
                            <span class="w-8 text-xs text-ink-500">{{ $unit }}</span>
                            <button type="button" x-on:click="remove(row.id)" title="Hapus"
                                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 hover:bg-ink-100 hover:text-rose-600">
                                <x-icon name="trash" class="h-4 w-4" />
                            </button>
                        </div>
                    </li>
                </template>
            </ul>

            <div class="flex items-center justify-between gap-3 border-t border-ink-100 px-4 py-3 sm:px-5">
                <p class="text-xs text-ink-500">Barang yang dihapus dari daftar ini tidak lagi dihitung kebutuhan kainnya.</p>
                <x-ui.button type="submit" icon="check">Simpan Pemakaian</x-ui.button>
            </div>
        </div>
    </form>
@else
    <x-ui.card title="Pemakaian Kain per Barang" padding="p-0">
        @if ($usages->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-ink-400">Belum diatur.</p>
        @else
            <table class="erp-table">
                <thead><tr><th>Barang</th><th class="text-right">Pemakaian / unit</th></tr></thead>
                <tbody>
                    @foreach ($usages as $usage)
                        <tr>
                            <td><span class="font-mono text-xs">{{ $usage->product?->sku }}</span> <span class="text-sm">{{ $usage->product?->name }}</span></td>
                            <td class="text-right text-sm tabular-nums">{{ $fabric->format($usage->quantity) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-ui.card>
@endcan
