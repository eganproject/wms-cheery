{{--
    Antrean paket yang isinya sudah diverifikasi di stasiun packing tetapi
    belum dikirim.

    Ada dua cara memprosesnya, dan keduanya memang perlu ada:

    - Memindai resi, di titik serah ke kurir. Yang tercatat terkirim adalah
      paket yang benar-benar ada di tangan.
    - Mencentang daftar, untuk borongan yang sudah pasti berangkat semua.

    Keduanya berbagi satu lingkup Alpine supaya paket yang barusan discan
    langsung hilang dari daftar sekaligus lepas dari centangnya.
--}}
<x-app-layout title="Siap Dikirim">
    <x-ui.page-header title="Siap Dikirim" icon="outbound"
                      subtitle="Paket yang sudah lengkap discan dan tinggal diproses pengirimannya.">
    </x-ui.page-header>


    <div x-data="dispatchStation({
             url: '{{ route('admin.outbounds.ready.scan') }}',
             remaining: {{ $readyCount }},
             {{-- Paket batal sengaja tidak masuk daftar yang bisa dicentang. --}}
             queued: @js($outbounds->reject(fn ($outbound) => $outbound->shipmentOrder?->isCancelled())->pluck('id')->values()->all()),
         })"
         {{-- Hasil pindaian kamera diperlakukan sama persis dengan scanner genggam. --}}
         x-on:camera-scan.window="code = $event.detail.code; submit()">

        @can('outbounds.post')
            {{-- ─────────────── Panel scan resi ─────────────── --}}
            <div class="frappe-card mb-4 overflow-hidden">
                <div class="flex items-start justify-between gap-4 px-4 pt-4 sm:px-5">
                    <div class="min-w-0">
                        <p class="text-xs text-ink-500">Serah ke kurir</p>
                        <h2 class="mt-0.5 text-lg font-semibold text-ink-950">
                            Scan resi untuk mengirim
                        </h2>
                        <p class="mt-0.5 text-xs leading-relaxed text-ink-500">
                            {{ auth()->user()->can('outbounds.approve')
                                ? 'Paket yang resinya discan langsung dikirim dan stoknya berkurang.'
                                : 'Paket yang resinya discan diajukan untuk disetujui.' }}
                        </p>
                    </div>

                    <dl class="flex shrink-0 items-start gap-4 text-right sm:gap-5">
                        <div>
                            <dd class="text-lg font-semibold leading-tight text-green-700" x-text="sent.length"></dd>
                            <dt class="text-xs text-ink-500">dikirim</dt>
                        </div>
                        <div>
                            <dd class="text-lg font-semibold leading-tight text-ink-950" x-text="remaining"></dd>
                            <dt class="text-xs text-ink-500">antre</dt>
                        </div>
                    </dl>
                </div>

                <div class="flex items-stretch gap-2 px-4 pt-4 sm:px-5">
                    @include('admin.partials.camera-scan', [
                        'scanTitle' => "'Scan resi untuk mengirim'",
                        'scanHint' => "remaining + ' paket menunggu di antrean.'",
                    ])

                    <form data-no-ajax @submit.prevent="submit()" class="min-w-0 flex-1">
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-ink-500">
                                <x-icon name="search" class="h-[18px] w-[18px]" />
                            </span>

                            <input x-ref="input" x-model="code" type="text" autocomplete="off" autocapitalize="off"
                                   spellcheck="false" :disabled="busy"
                                   placeholder="Scan atau ketik nomor resi…"
                                   class="block h-12 w-full rounded-lg pl-10 pr-24 font-mono text-base text-ink-950 placeholder:font-sans placeholder:text-sm disabled:opacity-50">

                            <div class="absolute inset-y-0 right-0 flex items-center pr-1.5">
                                <button type="submit" :disabled="busy || ! code.trim()"
                                        class="btn btn-primary h-9 px-3.5 sm:h-9">
                                    <span x-show="! busy">Kirim</span>
                                    <span x-show="busy" x-cloak class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                {{-- Ruang umpan balik: selalu ada, hanya isinya berganti. --}}
                <div class="px-4 pb-4 pt-3 sm:px-5">
                    <div class="flex min-h-[3.25rem] items-center gap-2.5 rounded-lg px-3 py-2 transition"
                         :class="feedback
                            ? (feedback.type === 'success' ? 'bg-green-100' : 'bg-red-100')
                            : 'bg-ink-50'">
                        <template x-if="feedback && feedback.type === 'success'">
                            <x-icon name="check-circle" class="h-4 w-4 shrink-0 text-green-700" />
                        </template>
                        <template x-if="feedback && feedback.type === 'error'">
                            <x-icon name="x-circle" class="h-4 w-4 shrink-0 text-red-600" />
                        </template>

                        <p class="overflow-hidden text-xs leading-4"
                           :class="feedback
                                ? (feedback.type === 'success' ? 'text-green-800' : 'text-red-700')
                                : 'text-ink-400'"
                           x-text="feedback ? feedback.message : 'Hasil scan muncul di sini.'"></p>
                    </div>
                </div>
            </div>

            {{-- ─────────────── Terkirim pada sesi ini ─────────────── --}}
            <div x-show="sent.length" x-cloak
                 class="frappe-card mb-4 overflow-hidden">
                <div class="flex items-center justify-between gap-3 border-b border-ink-100 bg-green-50 px-4 py-2.5">
                    <p class="text-xs text-emerald-700">Terkirim Sesi Ini</p>
                    <p class="text-xs text-emerald-700" x-text="`${sent.length} paket · ${sessionUnits} unit`"></p>
                </div>

                <ul class="scrollbar-thin max-h-44 divide-y divide-ink-50 overflow-y-auto">
                    <template x-for="entry in sent" :key="entry.id">
                        <li class="flex items-center gap-3 px-4 py-2.5">
                            <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">
                                <x-icon name="check" class="h-3 w-3" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-mono text-xs font-semibold text-ink-950" x-text="entry.tracking_number"></p>
                                <p class="truncate text-[11px] text-ink-400"
                                   x-text="[entry.code, entry.marketplace].filter(Boolean).join(' · ')"></p>
                            </div>
                            <span class="shrink-0 text-[11px] font-semibold"
                                  :class="entry.shipped ? 'text-emerald-600' : 'text-amber-600'"
                                  x-text="entry.shipped ? `${entry.units} unit` : 'menunggu setuju'"></span>
                            <span class="shrink-0 font-mono text-[11px] text-ink-400" x-text="entry.at"></span>
                        </li>
                    </template>
                </ul>
            </div>
        @endcan

        <form method="GET" action="{{ route('admin.outbounds.ready') }}" data-auto-submit
              class="frappe-card mb-4 flex flex-col gap-2 p-3 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex w-8 items-center justify-center text-ink-500">
                    <x-icon name="search" class="h-4 w-4" />
                </span>
                <x-text-input type="search" name="search" :value="request('search')"
                              placeholder="Cari nomor dokumen, penerima, atau resi..." class="pl-8" />
            </div>

            <x-ui.select name="marketplace" class="sm:w-44">
                <option value="">Semua toko</option>
                @foreach ($marketplaces as $marketplace)
                    <option value="{{ $marketplace }}" @selected(request('marketplace') === $marketplace)>{{ $marketplace }}</option>
                @endforeach
            </x-ui.select>

            <div class="flex items-center gap-2">
                <x-ui.button type="submit" variant="secondary" icon="filter" class="flex-1 sm:flex-none">Terapkan</x-ui.button>
                @if (request()->hasAny(['search', 'marketplace']))
                    <x-ui.button :href="route('admin.outbounds.ready')" variant="ghost" size="icon" title="Reset filter">
                        <x-icon name="refresh" class="h-4 w-4" />
                    </x-ui.button>
                @endif
            </div>
        </form>

        @if ($outbounds->isEmpty())
            <div class="frappe-card overflow-hidden">
                <x-ui.empty-state icon="check-circle" title="Tidak ada paket menunggu"
                                  description="Semua paket yang sudah discan sudah diproses. Paket baru muncul di sini begitu selesai discan di stasiun packing.">
                    @can('outbounds.scan')
                        <x-slot name="action">
                            <x-ui.button :href="route('admin.outbounds.marketplace')" icon="search">Buka Stasiun Packing</x-ui.button>
                        </x-slot>
                    @endcan
                </x-ui.empty-state>
            </div>
        @else
            {{-- Seluruh daftar berada di dalam satu form: centang beberapa paket, proses sekaligus. --}}
            <form method="POST" action="{{ route('admin.outbounds.ready.process') }}">
                @csrf

                {{--
                    Filter yang sedang aktif ikut terkirim.

                    Tanpa ini pemrosesan mengandalkan `back()`, yang membaca header
                    Referer — dan header itu tidak selalu ada, sedangkan cadangan
                    sesinya tidak pernah diperbarui pada navigasi AJAX. Akibatnya
                    operator terlempar ke daftar tanpa filter setelah memproses,
                    lalu harus menyaring ulang dari awal.
                --}}
                @foreach (['search', 'marketplace'] as $filter)
                    @if (request()->filled($filter))
                        <input type="hidden" name="{{ $filter }}" value="{{ request($filter) }}">
                    @endif
                @endforeach

                {{--
                    Bilah aksi berada di luar kartu daftarnya. Sempat diletakkan
                    di dalam kartu ber-overflow-hidden, dan di sana `sticky` tidak
                    pernah bekerja: elemen ber-overflow menjadi wadah gulirnya
                    sendiri, sehingga bilahnya ikut hanyut saat halaman digulir.
                --}}
                <div class="sticky top-[calc(var(--navbar-height)+3.75rem)] z-20 mb-3 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-ink-100 bg-white/95 px-4 py-2 backdrop-blur">
                    <label class="flex cursor-pointer items-center gap-2.5 text-sm text-ink-700">
                        <input type="checkbox" :checked="allChosen" x-on:change="toggleAll()"
                               :disabled="! available.length"
                               class="disabled:opacity-40">
                        <span x-text="selected.length
                            ? `${selected.length} paket dipilih`
                            : `Pilih semua (${available.length} paket)`"></span>
                    </label>

                    @can('outbounds.post')
                        <button type="submit" :disabled="! selected.length"
                                class="btn btn-primary disabled:cursor-not-allowed disabled:opacity-40">
                            <x-icon :name="auth()->user()->can('outbounds.approve') ? 'check' : 'clock'" class="h-4 w-4" />
                            {{ auth()->user()->can('outbounds.approve') ? 'Proses & Kirim' : 'Ajukan Persetujuan' }}
                        </button>
                    @endcan
                </div>

                <div class="frappe-card overflow-hidden">
                    {{--
                        Muncul saat seluruh isi halaman ini sudah discan satu per
                        satu. Tanpa ini kartunya menyusut menjadi bingkai kosong
                        tanpa penjelasan.
                    --}}
                    <div x-show="! available.length" x-cloak>
                        <x-ui.empty-state icon="check-circle" title="Semua paket di halaman ini sudah dikirim"
                                          description="Muat ulang halaman untuk melihat paket berikutnya dari antrean." />
                    </div>

                    <ul class="divide-y divide-ink-50">
                        @foreach ($outbounds as $outbound)
                            @php $cancelled = (bool) $outbound->shipmentOrder?->isCancelled(); @endphp

                            {{--
                                Tautan detail sengaja berada di luar <label>: elemen
                                interaktif di dalam label membuat kliknya ikut
                                mencentang baris, dan itu bukan yang dimaui orang
                                yang menekannya.
                            --}}
                            <li x-show="! isSent({{ $outbound->id }})"
                                @class([
                                    'flex items-start gap-3 px-4 py-3 transition',
                                    'bg-red-50/60' => $cancelled,
                                    'hover:bg-ink-50/50' => ! $cancelled,
                                ])
                                :class="selected.includes({{ $outbound->id }}) && 'bg-ink-50/70'">
                                <label class="flex min-w-0 flex-1 items-start gap-3 {{ $cancelled ? 'cursor-default' : 'cursor-pointer' }}">
                                    {{--
                                        Paket batal tetap ditampilkan, bukan disembunyikan:
                                        barangnya sudah turun dari rak dan seseorang harus
                                        mengembalikannya. Yang dicabut hanya kemampuan
                                        mencentangnya.
                                    --}}
                                    {{--
                                        Kedua sebab digabung dalam satu ikatan.

                                        Atribut disabled dari Blade sempat
                                        dipasang berdampingan dengan ikatan
                                        Alpine pada atribut yang sama — dan
                                        ikatannya menang, sehingga kotak centang
                                        paket batal hidup kembali begitu halaman
                                        selesai dimuat.
                                    --}}
                                    <input type="checkbox" name="ids[]" value="{{ $outbound->id }}" x-model.number="selected"
                                           :disabled="isSent({{ $outbound->id }}) || {{ $cancelled ? 'true' : 'false' }}"
                                           class="mt-1 shrink-0 disabled:opacity-40">

                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-mono text-sm font-semibold text-ink-950">{{ $outbound->code }}</span>
                                            @if ($outbound->marketplace)
                                                <x-ui.badge variant="blue">{{ $outbound->marketplace }}</x-ui.badge>
                                            @endif

                                            @if ($cancelled)
                                                <x-ui.badge variant="danger" icon="x-circle">Dibatalkan pembeli</x-ui.badge>
                                            @else
                                                <x-ui.badge variant="success" icon="check">
                                                    {{ number_format((int) $outbound->items_sum_quantity, 0, ',', '.') }} unit discan
                                                </x-ui.badge>
                                            @endif
                                        </div>

                                        <p class="mt-1 truncate font-mono text-xs text-ink-500">{{ $outbound->tracking_number }}</p>

                                        @if ($cancelled)
                                            <p class="text-xs font-medium text-red-700">
                                                Kembalikan {{ number_format((int) $outbound->items_sum_quantity, 0, ',', '.') }} unit ke rak,
                                                lalu hapus dokumen ini.
                                            </p>
                                        @endif

                                        <p class="truncate text-xs text-ink-400">
                                            {{ $outbound->recipient }}
                                            &middot; {{ $outbound->items_count }} SKU
                                            &middot; discan {{ $outbound->resi_verified_at?->diffForHumans() }}
                                            @if ($outbound->user) &middot; oleh {{ $outbound->user->name }} @endif
                                        </p>
                                    </div>
                                </label>

                                <a href="{{ route('admin.outbounds.show', $outbound) }}" title="Detail dokumen"
                                   class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                    <x-icon name="eye" class="h-4 w-4" />
                                </a>
                            </li>
                        @endforeach
                    </ul>

                    <x-ui.pagination :paginator="$outbounds" />
                </div>
            </form>
        @endif
    </div>
</x-app-layout>
