{{-- Kirim hasil potong di gudang ke penjahit (bisa sebagian). Variabel: $batch (dengan items.product), $tailors. --}}
<div x-data="{ open: false }" class="inline-flex">
    <x-ui.button type="button" size="sm" icon="truck" x-on:click="open = true">Kirim ke Penjahit</x-ui.button>
    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-[70] flex items-end justify-center p-3 sm:items-start sm:p-4 sm:pt-[12vh]" role="dialog" aria-modal="true">
            <div x-on:click="open = false" class="fixed inset-0 bg-ink-950/30"></div>
            <form method="POST" action="{{ route('admin.sewing-batches.send', $batch) }}" x-on:keydown.escape.window="open = false"
                  class="relative w-full max-w-sm space-y-3 rounded-2xl bg-white p-5 text-left shadow-xl">
                @csrf
                <div>
                    <h3 class="text-base font-semibold text-ink-950">Kirim {{ $batch->code }} ke Penjahit</h3>
                    <p class="text-xs text-ink-500">Boleh sebagian; sisanya tetap di gudang.</p>
                </div>
                <select name="supplier_id" required class="form-control w-full" data-native>
                    <option value="">Pilih penjahit…</option>
                    @foreach ($tailors as $tailor)
                        <option value="{{ $tailor->id }}">{{ $tailor->name }}</option>
                    @endforeach
                </select>
                <input type="date" name="date" value="{{ now()->format('Y-m-d') }}" required class="form-control w-full">
                <div class="space-y-1.5">
                    @foreach ($batch->items as $item)
                        <label class="flex items-center gap-2 text-sm">
                            <span class="min-w-0 flex-1 truncate"><span class="font-mono text-xs font-semibold">{{ $item->product->sku }}</span> <span class="text-xs text-ink-500">· ada {{ $item->quantity }}</span></span>
                            <input type="number" name="items[{{ $item->product_id }}]" value="{{ $item->quantity }}" min="0" max="{{ $item->quantity }}" step="1"
                                   class="form-control h-9 w-20 text-right tabular-nums">
                        </label>
                    @endforeach
                </div>
                <div class="flex justify-end gap-2 pt-1">
                    <x-ui.button type="button" variant="ghost" size="sm" x-on:click="open = false">Batal</x-ui.button>
                    <x-ui.button type="submit" size="sm" icon="truck">Kirim</x-ui.button>
                </div>
            </form>
        </div>
    </template>
</div>
