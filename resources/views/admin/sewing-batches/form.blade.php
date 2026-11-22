{{--
    Input manual hasil potong per SKU: disimpan di gudang atau sedang dijahit
    di penjahit. Dipakai untuk saldo yang sudah berjalan, atau mengubah batch.
--}}
@php
    $editing = (bool) $batch;
    $title = $editing ? 'Ubah Batch '.$batch->code : 'Input Manual Hasil Potong';
    $posisi = old('posisi', $editing ? ($batch->inWarehouse() ? 'gudang' : 'penjahit') : 'gudang');
@endphp
<x-app-layout :title="$title">
    <x-ui.page-header :title="$title"
                      :subtitle="$editing && $batch->cutting ? 'Dari cutting '.$batch->cutting->code.'. Jumlah tidak boleh kurang dari yang sudah diterima.' : 'Catat hasil potong yang sudah ada di gudang atau sedang dijahit, tanpa lewat Cutting.'"
                      :back="route('admin.fabric-cuttings.index', ['lihat' => $editing && ! $batch->inWarehouse() ? 'jahit' : 'gudang'])">
        @if ($editing && $batch->inbounds()->doesntExist())
            <x-slot name="actions">
                <x-ui.confirm-delete :action="route('admin.sewing-batches.destroy', $batch)" title="Hapus batch ini?"
                                     :description="$batch->code.' belum dipakai di penerimaan barang dan akan dihapus.'" />
            </x-slot>
        @endif
    </x-ui.page-header>

    <form method="POST" action="{{ $editing ? route('admin.sewing-batches.update', $batch) : route('admin.sewing-batches.store') }}"
          x-data="{ posisi: @js($posisi) }" class="space-y-6">
        @csrf
        @if ($editing)
            @method('PUT')
        @endif

        <x-ui.card title="Posisi Barang">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div class="md:col-span-2">
                    <div class="grid grid-cols-2 gap-1 rounded-xl bg-control p-1 sm:w-96">
                        <label class="cursor-pointer rounded-lg px-3 py-2 text-center text-sm font-semibold"
                               :class="posisi === 'gudang' ? 'bg-white text-ink-950 shadow-sm' : 'text-ink-500'">
                            <input type="radio" name="posisi" value="gudang" x-model="posisi" class="sr-only"> Di Gudang
                        </label>
                        <label class="cursor-pointer rounded-lg px-3 py-2 text-center text-sm font-semibold"
                               :class="posisi === 'penjahit' ? 'bg-white text-ink-950 shadow-sm' : 'text-ink-500'">
                            <input type="radio" name="posisi" value="penjahit" x-model="posisi" class="sr-only"> Di Penjahit
                        </label>
                    </div>
                </div>

                <div x-show="posisi === 'penjahit'" x-cloak class="md:col-span-2">
                    @if ($tailors->isEmpty())
                        <p class="rounded-lg bg-amber-50 px-3 py-2.5 text-sm text-amber-800">
                            Belum ada pemasok bertanda <b>Penjahit/Konveksi</b>. Centang di data
                            <a href="{{ route('admin.suppliers.index') }}" class="underline underline-offset-2">Pemasok</a> terlebih dahulu.
                        </p>
                    @else
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <x-ui.field label="Penjahit" for="supplier_id" :error="$errors->get('supplier_id')" required>
                                <x-ui.select id="supplier_id" name="supplier_id">
                                    <option value="">Pilih penjahit…</option>
                                    @foreach ($tailors as $tailor)
                                        <option value="{{ $tailor->id }}" @selected((int) old('supplier_id', $batch?->supplier_id) === $tailor->id)>{{ $tailor->name }}</option>
                                    @endforeach
                                </x-ui.select>
                            </x-ui.field>
                            <x-ui.field label="Tanggal dikirim ke penjahit" for="sent_at" :error="$errors->get('sent_at')" hint="Kosong = sama dengan tanggal potong.">
                                <x-text-input id="sent_at" name="sent_at" type="date" :value="old('sent_at', $batch?->sent_at?->format('Y-m-d'))" />
                            </x-ui.field>
                        </div>
                    @endif
                </div>

                <x-ui.field label="Tanggal potong" for="date" :error="$errors->get('date')" required>
                    <x-text-input id="date" name="date" type="date" :value="old('date', $batch?->date?->format('Y-m-d') ?? now()->format('Y-m-d'))" required />
                </x-ui.field>

                <x-ui.field label="Catatan" for="note" :error="$errors->get('note')">
                    <x-text-input id="note" name="note" type="text" :value="old('note', $batch?->note ?? ($editing ? null : 'Saldo awal'))" placeholder="Opsional" />
                </x-ui.field>
            </div>
        </x-ui.card>

        @include('admin.partials.line-items', [
            'products' => $products,
            'items' => $items,
            'mode' => 'sewing',
        ])

        <div class="flex justify-end">
            <x-ui.button type="submit" icon="check">{{ $editing ? 'Simpan Perubahan' : 'Simpan' }}</x-ui.button>
        </div>
    </form>
</x-app-layout>
