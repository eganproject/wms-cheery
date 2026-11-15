{{--
    Catat kain masuk. Satu baris = satu jenis kain dengan panjang/berat yang
    sama per roll; roll dengan panjang berbeda ditulis di baris terpisah.
--}}
@php
    $initialLines = old('lines', [[
        'fabric_id' => $preselect,
        'quantity' => '',
        'rolls' => 1,
        'lot' => '',
        'location' => '',
    ]]);
@endphp

<x-app-layout title="Catat Kain Masuk">
    <x-ui.page-header title="Catat Kain Masuk" subtitle="Isi panjang/berat per roll. Setelah disimpan, setiap roll mendapat kode QR untuk dicetak."
                      :back="route('admin.fabric-receipts.index')" />

    @if ($fabrics->isEmpty())
        <x-ui.card>
            <x-ui.empty-state icon="layers" title="Belum ada jenis kain aktif"
                              description="Daftarkan jenis kain dulu di Saldo Kain.">
                @can('fabrics.manage')
                    <x-slot name="action">
                        <x-ui.button :href="route('admin.fabrics.create')" icon="plus">Tambah Jenis Kain</x-ui.button>
                    </x-slot>
                @endcan
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <form method="POST" action="{{ route('admin.fabric-receipts.store') }}"
              x-data="{
                  fabrics: {{ Js::from($fabrics) }},
                  lines: {{ Js::from(array_values($initialLines)) }},
                  supplier: '{{ old('supplier_id') }}',
                  fabric(id) { return this.fabrics.find((item) => String(item.id) === String(id)) },
                  unit(line) { return this.fabric(line.fabric_id)?.unit ?? '' },
                  add(copy = null) { this.lines.push(copy ? { ...copy } : { fabric_id: '', quantity: '', rolls: 1, lot: '', location: '' }) },
                  remove(index) { this.lines.splice(index, 1); if (! this.lines.length) this.add() },
                  picked(line) { const fabric = this.fabric(line.fabric_id); if (fabric?.supplier_id && ! this.supplier) this.supplier = String(fabric.supplier_id) },
                  total(line) { return (Number(line.quantity) || 0) * (Number(line.rolls) || 0) },
                  format(value) { return Number(value.toFixed(3)).toLocaleString('id-ID') },
                  get rolls() { return this.lines.reduce((sum, line) => sum + (Number(line.rolls) || 0), 0) },
              }">
            @csrf

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div class="space-y-6 lg:col-span-2">
                    <x-ui.card title="Roll yang Diterima" padding="p-0">
                        <x-slot name="actions">
                            <p class="text-xs text-ink-500"><span class="font-semibold text-ink-950" x-text="rolls"></span> roll</p>
                        </x-slot>

                        @error('lines')
                            <p class="mx-4 mt-3 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 sm:mx-5">{{ $message }}</p>
                        @enderror
                        @if ($errors->has('lines.*'))
                            <p class="mx-4 mt-3 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 sm:mx-5">{{ collect($errors->get('lines.*'))->flatten()->first() }}</p>
                        @endif

                        <div class="divide-y divide-ink-50">
                            <template x-for="(line, index) in lines" :key="index">
                                <div class="grid grid-cols-2 gap-3 px-4 py-4 sm:grid-cols-12 sm:px-5">
                                    <div class="col-span-2 sm:col-span-5">
                                        <label class="form-label">Kain</label>
                                        <div class="flex items-center gap-2">
                                            <span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-ink-50 text-ink-300 ring-1 ring-inset ring-ink-100">
                                                <template x-if="fabric(line.fabric_id)?.image">
                                                    <img :src="fabric(line.fabric_id).image" alt="" class="h-full w-full object-cover">
                                                </template>
                                                <x-icon name="layers" class="h-4 w-4" x-show="! fabric(line.fabric_id)?.image" />
                                            </span>
                                            <select class="form-control min-w-0 flex-1" :name="`lines[${index}][fabric_id]`" x-model="line.fabric_id" x-on:change="picked(line)" required>
                                                <option value="">Pilih kain…</option>
                                                <template x-for="item in fabrics" :key="item.id">
                                                    <option :value="item.id" :selected="String(item.id) === String(line.fabric_id)" x-text="`${item.sku} — ${item.name}`"></option>
                                                </template>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="sm:col-span-3">
                                        <label class="form-label">Per roll <span class="text-ink-400" x-text="unit(line) ? `(${unit(line)})` : ''"></span></label>
                                        <input type="number" step="0.01" min="0.01" inputmode="decimal" required class="form-control text-right tabular-nums"
                                               :name="`lines[${index}][quantity]`" x-model="line.quantity" placeholder="50">
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="form-label">Jumlah roll</label>
                                        <input type="number" step="1" min="1" max="500" inputmode="numeric" required class="form-control text-right tabular-nums"
                                               :name="`lines[${index}][rolls]`" x-model="line.rolls">
                                    </div>
                                    <div class="col-span-2 flex items-end justify-end gap-1 sm:col-span-2">
                                        <button type="button" x-on:click="add(line)" title="Duplikat baris"
                                                class="inline-flex h-10 w-10 items-center justify-center rounded-lg text-ink-400 hover:bg-ink-100 hover:text-ink-950">
                                            <x-icon name="copy" class="h-4 w-4" />
                                        </button>
                                        <button type="button" x-on:click="remove(index)" title="Hapus baris"
                                                class="inline-flex h-10 w-10 items-center justify-center rounded-lg text-ink-400 hover:bg-ink-100 hover:text-rose-600">
                                            <x-icon name="trash" class="h-4 w-4" />
                                        </button>
                                    </div>
                                    <div class="sm:col-span-5">
                                        <input type="text" maxlength="60" class="form-control" :name="`lines[${index}][lot]`" x-model="line.lot" placeholder="Lot / no. celup (opsional)">
                                    </div>
                                    <div class="sm:col-span-4">
                                        <input type="text" maxlength="60" class="form-control" :name="`lines[${index}][location]`" x-model="line.location" placeholder="Lokasi rak (opsional)">
                                    </div>
                                    <p class="col-span-2 self-center text-right text-xs text-ink-500 sm:col-span-3">
                                        Total <span class="font-semibold text-ink-950" x-text="`${format(total(line))} ${unit(line)}`"></span>
                                    </p>
                                </div>
                            </template>
                        </div>

                        <div class="border-t border-ink-100 px-4 py-3 sm:px-5">
                            <x-ui.button type="button" variant="secondary" icon="plus" x-on:click="add()">Tambah Baris</x-ui.button>
                            <p class="mt-2 text-[11px] text-ink-400">Roll dengan panjang berbeda ditulis di baris terpisah. Tombol duplikat menyalin baris.</p>
                        </div>
                    </x-ui.card>
                </div>

                <div class="space-y-6">
                    <x-ui.card title="Dokumen">
                        <div class="space-y-4">
                            <x-ui.field label="Tanggal Terima" for="date" :error="$errors->get('date')" required>
                                <x-text-input id="date" name="date" type="date" :value="old('date', now()->format('Y-m-d'))" max="{{ now()->format('Y-m-d') }}" required />
                            </x-ui.field>

                            <x-ui.field label="Pemasok" for="supplier_id" :error="$errors->get('supplier_id')">
                                <select id="supplier_id" name="supplier_id" class="form-control" x-model="supplier">
                                    <option value="">—</option>
                                    @foreach ($suppliers as $supplier)
                                        <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                                    @endforeach
                                </select>
                            </x-ui.field>

                            <x-ui.field label="No. Surat Jalan" for="reference" :error="$errors->get('reference')">
                                <x-text-input id="reference" name="reference" type="text" :value="old('reference')" />
                            </x-ui.field>

                            <x-ui.field label="Catatan" for="note" :error="$errors->get('note')">
                                <x-text-input id="note" name="note" type="text" :value="old('note')" />
                            </x-ui.field>
                        </div>
                    </x-ui.card>

                    <div class="flex flex-col gap-2 sm:flex-row">
                        <x-ui.button type="submit" icon="check" class="flex-1">Simpan &amp; Buat QR</x-ui.button>
                        <x-ui.button :href="route('admin.fabric-receipts.index')" variant="secondary" class="flex-1">Batal</x-ui.button>
                    </div>
                </div>
            </div>
        </form>
    @endif
</x-app-layout>
