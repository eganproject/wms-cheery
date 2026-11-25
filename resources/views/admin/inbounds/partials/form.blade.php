@php
    $inbound = $inbound ?? null;
    $isEdit = (bool) $inbound;
@endphp

<form method="POST" action="{{ $isEdit ? route('admin.inbounds.update', $inbound) : route('admin.inbounds.store') }}"
      class="space-y-6">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-ui.card class="lg:col-span-2" title="Informasi Dokumen" subtitle="Data penerimaan barang dari pemasok.">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <x-ui.field label="Nomor Dokumen" hint="Dibuat otomatis oleh sistem.">
                    <x-text-input type="text" :value="$code" class="font-mono" disabled />
                </x-ui.field>

                <x-ui.field label="Tanggal" info="Tanggal barang diterima di gudang. Stok bertambah pada tanggal ini setelah disetujui." for="date" :error="$errors->get('date')" required>
                    <x-text-input id="date" name="date" type="date"
                                  :value="old('date', $inbound?->date?->format('Y-m-d') ?? now()->format('Y-m-d'))" required />
                </x-ui.field>

                <div x-data="{
                        supplier: @js((string) old('supplier_id', $inbound?->supplier_id ?? '')),
                        returns: @js((object) ($openReturns ?? [])),
                        batches: @js((object) ($sewingBatches ?? [])),
                        batch: @js((string) old('sewing_batch_id', $inbound?->sewing_batch_id ?? '')),
                        selectedBatch() {
                            return (this.batches[this.supplier] ?? []).find((item) => String(item.id) === String(this.batch)) ?? null;
                        },
                        fill() {
                            const found = (this.batches[this.supplier] ?? []).find((item) => String(item.id) === String(this.batch));
                            if (found) window.dispatchEvent(new CustomEvent('resi-items-found', { detail: found.items }));
                        },
                     }"
                     x-on:change="if ($event.target.name === 'supplier_id') { supplier = $event.target.value; if (! (batches[supplier] ?? []).some((item) => String(item.id) === String(batch))) batch = '' }"
                     class="space-y-2">
                <x-ui.field label="Pemasok" info="Pemasok asal barang. Boleh dikosongkan untuk penerimaan non-pembelian." for="supplier_id" :error="$errors->get('supplier_id')">
                    <x-ui.select id="supplier_id" name="supplier_id" data-placeholder="Pilih pemasok…">
                        <option value="">Pilih pemasok…</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected(old('supplier_id', $inbound?->supplier_id) == $supplier->id)>
                                {{ $supplier->code }} — {{ $supplier->name }}
                            </option>
                        @endforeach
                    </x-ui.select>
                    @can('suppliers.create')
                        <p class="text-xs text-ink-400">
                            Pemasok belum terdaftar?
                            <a href="{{ route('admin.suppliers.create') }}" class="font-medium text-ink-950 underline-offset-4 hover:underline">Tambah pemasok</a>
                        </p>
                    @endcan
                </x-ui.field>

                {{-- Batch WIP Jahit dari penjahit ini (Cutting & Jahit). --}}
                <template x-if="(batches[supplier] ?? []).length || batch">
                    <div class="rounded-lg bg-sky-50 px-3 py-2.5 text-xs text-sky-900 ring-1 ring-inset ring-sky-200">
                        <label class="font-semibold" for="sewing_batch_id">Barang jadi dari batch WIP Jahit</label>
                        <div class="mt-1.5 flex gap-2">
                            <select id="sewing_batch_id" name="sewing_batch_id" x-model="batch" x-on:change.stop="fill()" class="form-control h-9 flex-1 bg-white text-xs">
                                <option value="">Bukan dari batch jahit</option>
                                <template x-for="item in (batches[supplier] ?? [])" :key="item.id">
                                    <option :value="item.id" :selected="String(item.id) === String(batch)" x-text="item.label"></option>
                                </template>
                            </select>
                        </div>
                        <template x-if="selectedBatch()">
                            <div class="mt-2 rounded-md bg-white px-2.5 py-2 ring-1 ring-inset ring-sky-200" data-batch-detail>
                                <p>Diberikan ke penjahit: <span class="font-semibold" x-text="selectedBatch().sent_at"></span></p>
                                <ul class="mt-1 space-y-0.5">
                                    <template x-for="row in selectedBatch().skus" :key="row.sku">
                                        <li class="flex justify-between gap-2">
                                            <span><span class="font-mono font-medium" x-text="row.sku"></span> <span class="text-sky-800" x-text="row.name"></span></span>
                                            <span class="shrink-0" x-text="`sisa ${row.quantity} pcs`"></span>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                        </template>
                        <p class="mt-1">Memilih batch mengisi baris barang dengan sisa per varian; ubah qty sesuai yang datang. Batch berkurang setelah penerimaan disetujui.</p>
                    </div>
                </template>
                <x-input-error :messages="$errors->get('sewing_batch_id')" />

                {{-- Pengingat retur yang masih ditunggu dari pemasok ini. --}}
                <template x-if="returns[supplier] && returns[supplier].length">
                    <div class="rounded-lg bg-amber-50 px-3 py-2.5 text-xs text-amber-900 ring-1 ring-inset ring-amber-200" data-open-returns>
                        <p class="font-semibold"
                           x-text="`Ada ${returns[supplier].reduce((sum, item) => sum + item.units, 0)} unit retur dari pemasok ini yang belum diganti.`"></p>
                        <p class="mt-0.5">Bila barang yang datang adalah pengganti retur, catat lewat tombol <span class="font-medium">Terima Pengganti</span> di dokumen returnya, bukan di sini, supaya returnya ikut tertutup.</p>
                        <ul class="mt-1.5 space-y-0.5">
                            <template x-for="item in returns[supplier]" :key="item.code">
                                <li>
                                    <a :href="item.url" target="_blank" class="font-mono font-medium underline underline-offset-2" x-text="item.code"></a>
                                    <span x-text="` · ${item.units} unit · ${item.status}`"></span>
                                </li>
                            </template>
                        </ul>
                    </div>
                </template>
                </div>

                <x-ui.field label="Nomor Referensi" info="Nomor surat jalan atau faktur dari pemasok, supaya dokumen mudah dicocokkan." for="reference" :error="$errors->get('reference')"
                            hint="Nomor surat jalan / faktur pemasok.">
                    <x-text-input id="reference" name="reference" type="text" :value="old('reference', $inbound?->reference)"
                                  class="font-mono" placeholder="SJ-00123" />
                </x-ui.field>

                <x-ui.field label="Catatan" for="note" :error="$errors->get('note')" class="sm:col-span-2">
                    <textarea id="note" name="note" rows="2" placeholder="Catatan tambahan (opsional)"
                              class="form-control">{{ old('note', $inbound?->note) }}</textarea>
                </x-ui.field>
            </div>
        </x-ui.card>

        <x-ui.card title="Aksi" subtitle="Simpan sebagai draft atau langsung tambahkan ke stok.">
            <div class="space-y-3">
                @can('inbounds.post')
                    <x-ui.button type="submit" name="submit" value="1"
                                 :icon="auth()->user()->can('inbounds.approve') ? 'check' : 'clock'" class="w-full">
                        {{ auth()->user()->can('inbounds.approve') ? 'Simpan & Setujui' : 'Simpan & Ajukan' }}
                    </x-ui.button>
                @endcan

                <x-ui.button type="submit" variant="secondary" icon="document" class="w-full">
                    Simpan sebagai Draft
                </x-ui.button>

                <x-ui.button :href="route('admin.inbounds.index')" variant="ghost" class="w-full">Batal</x-ui.button>
            </div>

            <p class="mt-4 flex items-start gap-2 rounded-xl bg-ink-50 p-3 text-[11px] leading-relaxed text-ink-500">
                <x-icon name="info" class="mt-px h-3.5 w-3.5 shrink-0 text-ink-300" />
                Stok hanya bertambah setelah dokumen disetujui. Draft masih bisa diubah kapan saja.
            </p>
        </x-ui.card>
    </div>

    @include('admin.partials.line-items', [
        'products' => $products,
        'items' => $inbound?->items,
        'mode' => 'inbound',
    ])
</form>
