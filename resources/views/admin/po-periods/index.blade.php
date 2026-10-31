{{--
    Lama PO per produk (riwayat dengan tanggal + jam berlaku) dan aturan
    batas kirim pesanan Normal. Dasar batas kirim setiap resi di Pesanan.
--}}
@php
    $fmt = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->translatedFormat('d M Y H:i') : '—';
    $canEdit = auth()->user()->can('products.update');
@endphp

<x-app-layout title="Lama PO">
    <x-ui.page-header title="Lama PO" icon="calendar"
                      subtitle="Lama Pre Order tiap produk, sama seperti setelan di TikTok. Batas kirim resi = waktu bayar + lama PO yang berlaku saat itu.">
        <x-slot name="actions">
            <x-ui.button :href="route('admin.orders.index', ['range' => 'semua', 'deadline' => 'missing'])" variant="secondary" icon="shopping-cart">Lihat Pesanan</x-ui.button>
        </x-slot>
    </x-ui.page-header>

    @if ($missingOrders > 0)
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm" data-po-missing>
            <p class="flex items-center gap-2 font-semibold text-amber-900">
                <x-icon name="warning" class="h-4 w-4" /> {{ number_format($missingOrders, 0, ',', '.') }} resi belum punya batas kirim
            </p>
            <p class="mt-1 text-xs text-amber-800">
                Produknya belum punya lama PO yang berlaku pada waktu bayar pesanannya (ditandai "Belum diisi" di tabel).
                Untuk pesanan yang sudah berjalan, pilih tanggal mulai sebelum pesanan tertua
                @if ($oldestOpen) (resi belum dikirim tertua dibayar <strong>{{ $fmt($oldestOpen) }}</strong>)@endif.
            </p>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-1">
            <x-ui.card title="Pesanan Normal" subtitle="Pesanan yang bukan Pre-order di berkas TikTok.">
                <form method="POST" action="{{ route('admin.po-periods.settings') }}" class="space-y-3">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="search" value="{{ $search }}">
                    <x-ui.field label="Harus dikirim paling lambat" for="normal_ship_days" :error="$errors->get('normal_ship_days')"
                                hint="Hari setelah dibayar. Instan dan besok sampai selalu hari yang sama.">
                        <div class="flex items-center gap-2">
                            <x-text-input id="normal_ship_days" name="normal_ship_days" type="number" min="0" max="30" class="w-24"
                                          :value="old('normal_ship_days', $normalDays)" :disabled="! $canEdit" />
                            <span class="text-sm text-ink-600">hari</span>
                        </div>
                    </x-ui.field>
                    @if ($canEdit)
                        <x-ui.button type="submit" variant="secondary" icon="check">Simpan</x-ui.button>
                    @endif
                </form>
            </x-ui.card>

            @if ($canEdit)
                <x-ui.card title="Tambah Periode" subtitle="Centang produk di tabel, lalu isi kapan mulai berlaku dan berapa hari.">
                    <div class="space-y-3">
                        <x-ui.field label="Mulai berlaku" for="starts_at" required :error="$errors->get('starts_at')"
                                    hint="Tanggal dan jam, sama seperti saat lama PO diubah di TikTok. Berlaku sampai periode berikutnya dimulai.">
                            <x-text-input id="starts_at" name="starts_at" type="datetime-local" form="po-form" class="w-full"
                                          :value="old('starts_at', $now->format('Y-m-d\TH:i'))" required />
                        </x-ui.field>
                        <x-ui.field label="Lama PO" for="days" required :error="$errors->get('days')"
                                    hint="Isi 0 bila produk siap stok (bukan PO): pesanannya memakai aturan Normal.">
                            <div class="flex items-center gap-2">
                                <x-text-input id="days" name="days" type="number" min="0" max="365" form="po-form" class="w-24"
                                              :value="old('days')" required />
                                <span class="text-sm text-ink-600">hari</span>
                            </div>
                        </x-ui.field>
                        <x-ui.field label="Catatan" for="note" :error="$errors->get('note')">
                            <x-text-input id="note" name="note" form="po-form" class="w-full" :value="old('note')" placeholder="mis. ikut setelan TikTok 8 Jun" />
                        </x-ui.field>
                        <x-input-error :messages="$errors->get('owners')" />
                        <x-ui.button type="submit" form="po-form" icon="check" class="w-full">Simpan untuk produk yang dicentang</x-ui.button>
                    </div>
                </x-ui.card>
            @endif
        </div>

        <div class="min-w-0 lg:col-span-2">
            <div class="frappe-card">
                <form method="GET" action="{{ route('admin.po-periods.index') }}" data-auto-submit class="flex items-center gap-2 border-b border-ink-100 p-3">
                    <div class="relative flex-1">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex w-8 items-center justify-center text-ink-500">
                            <x-icon name="search" class="h-4 w-4" />
                        </span>
                        <x-text-input type="search" name="search" :value="$search" placeholder="Cari produk atau SKU..." class="pl-8" />
                    </div>
                    @if ($search !== '')
                        <x-ui.button :href="route('admin.po-periods.index')" variant="ghost" size="icon" title="Reset">
                            <x-icon name="close" class="h-4 w-4" />
                        </x-ui.button>
                    @endif
                </form>

                <form method="POST" action="{{ route('admin.po-periods.store') }}" id="po-form" x-data="{ all: false }">
                    @csrf
                    <input type="hidden" name="search" value="{{ $search }}">
                    <div class="overflow-x-auto">
                        <table class="erp-table" data-po-table>
                            <thead>
                                <tr>
                                    @if ($canEdit)
                                        <th class="w-8"><input type="checkbox" class="rounded border-ink-300" aria-label="Pilih semua"
                                                               @change="all = $event.target.checked; $root.querySelectorAll('[data-owner]').forEach(el => el.checked = all)"></th>
                                    @endif
                                    <th>Produk</th>
                                    <th><span class="inline-flex items-center gap-1">Berlaku sekarang <x-ui.hint text="Lama PO untuk pesanan yang dibayar saat ini." /></span></th>
                                    <th><span class="inline-flex items-center gap-1">Riwayat <x-ui.hint text="Setiap periode berlaku mulai waktunya sampai periode berikutnya dimulai. Resi memakai periode yang berlaku pada waktu bayarnya." /></span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($owners as $owner)
                                    @php
                                        $history = $periods->get($owner['key'], collect());
                                        $current = $history->filter(fn ($period) => $period->starts_at->lte($now))->last();
                                        $gap = $missing->get($owner['key']);
                                    @endphp
                                    <tr @class(['bg-amber-50/50' => $gap])>
                                        @if ($canEdit)
                                            <td class="align-top"><input type="checkbox" name="owners[]" value="{{ $owner['key'] }}" data-owner
                                                                         class="rounded border-ink-300" @checked(in_array($owner['key'], old('owners', []), true))
                                                                         aria-label="Pilih {{ $owner['name'] }}"></td>
                                        @endif
                                        <td class="align-top">
                                            <p class="text-sm font-medium text-ink-950">{{ $owner['name'] }}
                                                @unless ($owner['active']) <x-ui.badge>Nonaktif</x-ui.badge> @endunless
                                            </p>
                                            <p class="max-w-xs truncate text-[11px] text-ink-400" title="{{ $owner['detail'] }}">{{ $owner['detail'] }}</p>
                                            @if ($gap)
                                                <p class="mt-1 text-[11px] font-medium text-amber-800">
                                                    {{ $gap['orders'] }} resi belum punya batas kirim · bayar tertua {{ $fmt($gap['oldest']) }}
                                                </p>
                                            @endif
                                        </td>
                                        <td class="align-top whitespace-nowrap">
                                            @if ($current)
                                                <span class="text-sm font-semibold text-ink-950">{{ $current->days ? $current->days.' hari' : 'Siap stok' }}</span>
                                            @elseif ($gap)
                                                <x-ui.badge variant="warning">Belum diisi</x-ui.badge>
                                            @else
                                                <span class="text-xs text-ink-400">—</span>
                                            @endif
                                        </td>
                                        <td class="align-top">
                                            @if ($history->isEmpty())
                                                <span class="text-xs text-ink-400">Belum ada periode</span>
                                            @else
                                                <ul class="space-y-1">
                                                    @foreach ($history as $index => $period)
                                                        @php $next = $history->values()->get($loop->index + 1); @endphp
                                                        <li class="flex items-center gap-2 text-xs">
                                                            <span class="text-ink-700">
                                                                {{ $period->starts_at->translatedFormat('d M Y H:i') }}
                                                                <span class="text-ink-400">→ {{ $next ? $next->starts_at->copy()->subMinute()->translatedFormat('d M Y H:i') : 'seterusnya' }}</span>
                                                            </span>
                                                            <span class="font-semibold text-ink-950">{{ $period->days ? $period->days.' hari' : 'siap stok' }}</span>
                                                            @if ($period->note)
                                                                <span class="truncate text-ink-400" title="{{ $period->note }}">· {{ $period->note }}</span>
                                                            @endif
                                                            @if ($canEdit)
                                                                <button type="submit" form="po-del-{{ $period->id }}" class="ml-auto rounded p-0.5 text-ink-400 hover:bg-rose-50 hover:text-rose-600"
                                                                        title="Hapus periode ini" onclick="return confirm('Hapus periode lama PO ini?')">
                                                                    <x-icon name="trash" class="h-3.5 w-3.5" />
                                                                </button>
                                                            @endif
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4">
                                            <x-ui.empty-state icon="box" title="Produk tidak ditemukan" description="Coba kata kunci lain." />
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </form>

                @if ($canEdit)
                    @foreach ($periods->flatten() as $period)
                        <form method="POST" action="{{ route('admin.po-periods.destroy', $period) }}" id="po-del-{{ $period->id }}" class="hidden">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="search" value="{{ $search }}">
                        </form>
                    @endforeach
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
