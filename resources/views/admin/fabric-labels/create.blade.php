<x-app-layout title="Cetak Label QR Roll">
    <x-ui.page-header title="Cetak Label QR Roll"
                      subtitle="Isi QR = kode roll (SKU kain + nomor roll). Saat discan langsung terbaca kain dan sisa roll."
                      :back="$back" />

    <form method="POST" action="{{ route('admin.fabric-labels.pdf') }}" target="_blank" data-no-ajax
          x-data="qrLabels({
              items: @js($items),
              perPage: @js($layouts->map(fn ($layout) => $layout['per_page'])),
              stacked: @js($layouts->map(fn ($layout) => $layout['stacked'])),
              sheets: @js($layouts->map(fn ($layout) => $layout['sheet'])),
              gaps: @js($layouts->map(fn ($layout) => $layout['sheet'] || $layout['cols'] < 2 ? null : $layout['gap_x'])),
              layout: @js($defaultLayout),
              maxLabels: {{ $maxLabels }},
              maxCopies: {{ $maxCopies }},
          })"
          class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        @csrf

        <div class="space-y-4 lg:col-span-2">
            <x-ui.card title="Roll yang dicetak" subtitle="Satu label per salinan. Angka di label adalah panjang/berat awal roll; sisa terbaru selalu dari scan.">
                <x-input-error :messages="$errors->get('items')" />

                <template x-if="items.length === 0">
                    <div class="rounded-xl border border-dashed border-ink-200 px-6 py-10 text-center">
                        <p class="text-sm font-semibold text-ink-950">Belum ada roll</p>
                        <p class="mt-1 text-sm text-ink-500">Centang roll di halaman Kain Masuk atau detail kain, lalu tekan Cetak Label QR.</p>
                    </div>
                </template>

                <div x-show="items.length > 0" x-cloak class="overflow-hidden rounded-xl border border-ink-100">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-ink-100 bg-ink-50/60 px-3 py-2">
                        <p class="text-xs text-ink-500"><span x-text="items.length"></span> roll</p>
                        <div class="flex items-center gap-2">
                            <label class="flex items-center gap-2 rounded-lg bg-white px-2.5 py-1 ring-1 ring-inset ring-ink-200">
                                <span class="whitespace-nowrap text-xs text-ink-500">Label per roll</span>
                                <input type="number" min="1" max="{{ $maxCopies }}" x-model="bulkCopies" inputmode="numeric"
                                       class="w-14 border-0 bg-transparent p-0 text-center text-sm font-semibold shadow-none focus:ring-0">
                            </label>
                            <button type="button" x-on:click="applyCopies()" class="btn btn-default">Terapkan ke semua</button>
                        </div>
                    </div>

                    <ul class="max-h-[60vh] divide-y divide-ink-50 overflow-y-auto">
                        <template x-for="(item, index) in items" :key="item.id">
                            <li class="flex items-center gap-3 px-3 py-2.5">
                                <input type="hidden" :name="`items[${index}][id]`" :value="item.id">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-mono text-xs font-semibold text-ink-950" x-text="item.sku"></p>
                                    <p class="truncate text-sm text-ink-600">
                                        <span x-text="item.name"></span>
                                        <span class="font-medium text-ink-950" x-text="` — ${item.variant}`"></span>
                                    </p>
                                </div>
                                <label class="flex shrink-0 items-center gap-1.5">
                                    <span class="sr-only">Jumlah label</span>
                                    <input type="number" min="1" max="{{ $maxCopies }}" required inputmode="numeric"
                                           :name="`items[${index}][copies]`" x-model.number="item.copies"
                                           class="form-control w-20 text-center">
                                    <span class="text-xs text-ink-400">label</span>
                                </label>
                                <button type="button" x-on:click="remove(index)" title="Hapus dari daftar"
                                        class="btn btn-ghost btn-icon shrink-0 text-ink-400 hover:text-ink-950">
                                    <x-icon name="close" class="h-4 w-4" />
                                </button>
                            </li>
                        </template>
                    </ul>
                </div>
            </x-ui.card>
        </div>

        <div class="space-y-4">
            @include('admin.fabric-labels.options')
        </div>
    </form>
</x-app-layout>
