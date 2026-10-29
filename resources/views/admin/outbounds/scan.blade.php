<x-app-layout title="Scan Pesanan Marketplace">
    <div x-data="documentScanner({
            urls: {
                resi: '{{ route('admin.outbounds.scan.resi', $outbound) }}',
                item: '{{ route('admin.outbounds.scan.item', $outbound) }}',
            },
            progress: {{ Js::from($progress) }},
         })"
         x-on:camera-scan.window="code = $event.detail.code; submit()"
         class="w-full">

        <x-ui.page-header :title="'Scan '.$outbound->code" :back="route('admin.outbounds.show', $outbound)">
            <x-slot name="indicator">
                <x-ui.badge variant="info">{{ $outbound->marketplace }}</x-ui.badge>
            </x-slot>
            <x-slot name="actions">
                <div class="text-right">
                    <p class="text-lg font-semibold leading-tight text-ink-950">
                        <span x-text="progress.scanned"></span><span class="text-ink-400">/{{ $outbound->totalQuantity() }}</span>
                    </p>
                    <p class="text-[11px] text-ink-500">unit discan</p>
                </div>
            </x-slot>
        </x-ui.page-header>

        {{-- Identitas pesanan: informasi, bukan form --}}
        <div class="frappe-card mb-4 grid grid-cols-1 gap-x-6 gap-y-2 px-4 py-3 text-sm sm:grid-cols-3">
            <div class="min-w-0">
                <p class="text-xs text-ink-500">No. Resi</p>
                <p class="break-all font-mono text-ink-950">{{ $outbound->tracking_number }}</p>
            </div>
            <div class="min-w-0">
                <p class="text-xs text-ink-500">Penerima</p>
                <p class="truncate text-ink-950">{{ $outbound->recipient }}</p>
            </div>
            <div class="min-w-0">
                <p class="text-xs text-ink-500">No. Pesanan</p>
                <p class="truncate text-ink-950">{{ $outbound->shipmentOrder?->order_number ?: '—' }}</p>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-5">
            {{-- Panel scan --}}
            <div class="space-y-4 lg:col-span-3">
                <div class="frappe-card overflow-hidden transition"
                     :class="progress.ready ? 'border-green-500 bg-green-50 ring-1 ring-green-500' : ''">

                    {{-- Instruksi besar: apa yang harus dilakukan sekarang --}}
                    <div class="px-5 pt-6 text-center sm:px-10">
                        <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-medium"
                              :class="progress.ready ? 'bg-green-100 text-green-800' : 'bg-control text-ink-700'">
                            <span class="inline-flex h-4 w-4 items-center justify-center rounded-full text-[10px] font-bold"
                                  :class="progress.ready ? 'bg-green-600 text-white' : 'bg-ink-950 text-white'"
                                  x-text="isResiStage ? '1' : '2'"></span>
                            <span x-text="isResiStage ? 'Langkah 1 dari 2' : 'Langkah 2 dari 2'"></span>
                        </span>

                        <h2 class="mt-3 text-xl font-semibold sm:text-2xl"
                            :class="progress.ready ? 'text-green-800' : 'text-ink-950'"
                            x-text="progress.ready ? 'Semua barang sudah discan' : (isResiStage ? 'Scan resi pesanan' : 'Scan barcode barang')"></h2>

                        <p class="mx-auto mt-2 max-w-md text-sm leading-relaxed"
                           :class="progress.ready ? 'text-green-800' : 'text-ink-500'"
                           x-text="progress.ready
                                ? '{{ auth()->user()->can('outbounds.approve') ? 'Isi paket sudah sesuai pesanan. Tekan Proses & Kirim untuk mengurangi stok.' : 'Isi paket sudah sesuai. Ajukan persetujuan untuk diproses.' }}'
                                : (isResiStage
                                    ? 'Cocokkan label resi pada paket dengan dokumen ini.'
                                    : `Sisa ${progress.total - progress.scanned} unit lagi. Setiap scan menambah satu unit.`)"></p>
                    </div>

                    {{-- Input scan --}}
                    <div class="p-5 sm:p-8 sm:pt-6">
                        <div class="flex items-stretch gap-2" x-show="! progress.ready">
                            @include('admin.partials.camera-scan', [
                                'scanStep' => 'isResiStage ? 1 : 2',
                                'scanTitle' => "isResiStage ? 'Scan label resi' : 'Scan barcode barang'",
                                'scanHint' => "isResiStage
                                    ? 'Arahkan ke label resi pada paket.'
                                    : progress.scanned + '/' + progress.total + ' unit terpindai.'",
                            ])

                        <form data-no-ajax @submit.prevent="submit()" class="min-w-0 flex-1">
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex w-12 items-center justify-center text-ink-500">
                                    <x-icon name="search" class="h-5 w-5" />
                                </span>

                                <input x-ref="input" x-model="code" type="text" autocomplete="off" autocapitalize="off"
                                       spellcheck="false"
                                       :placeholder="isResiStage ? 'Scan atau ketik nomor resi…' : 'Scan barcode atau ketik SKU barang…'"
                                       class="block h-14 w-full rounded-lg pl-12 pr-28 font-mono text-lg text-ink-950 placeholder:font-sans placeholder:text-sm disabled:opacity-60">

                                <div class="absolute inset-y-0 right-0 flex items-center pr-2">
                                    <button type="submit" :disabled="! code.trim()"
                                            class="btn btn-primary h-10 px-4 sm:h-10">
                                        <span x-show="! busy">Proses</span>
                                        <span x-show="busy" x-cloak class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
                                    </button>
                                </div>
                            </div>
                        </form>
                        </div>

                        {{-- Umpan balik scan terakhir --}}
                        <template x-if="feedback">
                            <div class="mt-4 flex items-start gap-3 rounded-lg p-3.5"
                                 :class="feedback.type === 'success' ? 'bg-green-100' : 'bg-red-100'">
                                <template x-if="feedback.type === 'success'">
                                    <x-icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0 text-green-700" />
                                </template>
                                <template x-if="feedback.type === 'error'">
                                    <x-icon name="x-circle" class="mt-0.5 h-4 w-4 shrink-0 text-red-600" />
                                </template>
                                <p class="text-sm"
                                   :class="feedback.type === 'success' ? 'text-green-800' : 'text-red-700'"
                                   x-text="feedback.message"></p>
                            </div>
                        </template>

                        {{-- Progres --}}
                        <div class="mt-6" x-show="! isResiStage || progress.ready">
                            <div class="mb-2 flex items-center justify-between text-xs">
                                <span :class="progress.ready ? 'text-green-800' : 'text-ink-500'">Progres scan barang</span>
                                <span class="font-semibold" :class="progress.ready ? 'text-green-800' : 'text-ink-950'">
                                    <span x-text="progress.percentage"></span>%
                                </span>
                            </div>
                            <div class="h-2 w-full overflow-hidden rounded-full"
                                 :class="progress.ready ? 'bg-green-200' : 'bg-control'">
                                <div class="h-full rounded-full transition-all duration-300"
                                     :class="progress.ready ? 'bg-green-600' : 'bg-blue-500'"
                                     :style="`width: ${progress.percentage}%`"></div>
                            </div>
                        </div>

                        {{-- Aksi penyelesaian --}}
                        <template x-if="progress.ready">
                            @can('outbounds.post')
                                <form method="POST" action="{{ route('admin.outbounds.submit', $outbound) }}" class="mt-6">
                                    @csrf
                                    <button type="submit"
                                            class="btn btn-lg w-full bg-green-600 text-white hover:bg-green-700">
                                        <x-icon :name="auth()->user()->can('outbounds.approve') ? 'check' : 'clock'" class="h-4 w-4" />
                                        {{ auth()->user()->can('outbounds.approve') ? 'Proses & Kirim' : 'Ajukan Persetujuan' }}
                                    </button>
                                </form>
                            @endcan
                        </template>
                    </div>
                </div>

                {{-- Riwayat scan --}}
                <template x-if="history.length">
                    <div class="frappe-card overflow-hidden">
                        <div class="border-b border-ink-100 px-4 py-2.5">
                            <p class="text-sm font-semibold text-ink-950">Scan Terakhir</p>
                        </div>
                        <ul class="divide-y divide-ink-100">
                            <template x-for="entry in history" :key="entry.id">
                                <li class="flex items-center gap-3 px-4 py-2.5">
                                    <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full"
                                          :class="entry.type === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'">
                                        <template x-if="entry.type === 'success'"><x-icon name="check" class="h-3 w-3" /></template>
                                        <template x-if="entry.type === 'error'"><x-icon name="close" class="h-3 w-3" /></template>
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-xs text-ink-700" x-text="entry.message"></p>
                                        <p class="truncate font-mono text-[11px] text-ink-400" x-text="entry.code"></p>
                                    </div>
                                    <span class="shrink-0 font-mono text-[11px] text-ink-400" x-text="entry.at"></span>
                                </li>
                            </template>
                        </ul>
                    </div>
                </template>
            </div>

            {{-- Daftar barang yang harus discan --}}
            <div class="lg:col-span-2">
                <div class="frappe-card overflow-hidden lg:sticky lg:top-[7.5rem]">
                    <div class="border-b border-ink-100 px-4 py-3">
                        <p class="text-base font-semibold text-ink-950">Barang yang Harus Discan</p>
                        <p class="mt-0.5 text-xs text-ink-500">
                            {{ $outbound->items->count() }} SKU &middot; {{ $outbound->totalQuantity() }} unit
                            @if ($outbound->shipment_order_id)
                                &middot; dari data import
                            @endif
                        </p>
                        <p class="mt-1.5 flex items-start gap-1.5 text-[11px] leading-relaxed text-ink-500">
                            <x-icon name="info" class="mt-px h-3.5 w-3.5 shrink-0" />
                            Setiap barang bisa discan lewat barcode maupun SKU-nya.
                        </p>
                    </div>

                    {{--
                        Paket yang sedang dirakit operator.

                        Daftar di bawah berisi barang lepas, dan tanpa kartu
                        ini jumlahnya tidak cocok dengan apa pun yang tertulis
                        di label paket — operator memegang satu label bertulis
                        "Paket Servis 10K ×2" sementara layar meminta tujuh
                        barang yang seolah tak berhubungan.
                    --}}
                    @if ($outbound->bundles->isNotEmpty())
                        <div class="border-b border-blue-200 bg-blue-50 px-4 py-3 text-blue-800">
                            <p class="text-xs text-blue-700">Isi paket yang dirakit</p>
                            <ul class="mt-2 space-y-1.5">
                                @foreach ($outbound->bundles as $bundle)
                                    <li class="flex items-center justify-between gap-3 text-xs">
                                        <span class="min-w-0 truncate">
                                            <span class="font-mono text-blue-700">{{ $bundle->bundle?->sku }}</span>
                                            <span class="text-blue-400">·</span>
                                            {{ $bundle->bundle?->name }}
                                        </span>
                                        <span class="shrink-0 font-semibold">&times;{{ $bundle->quantity }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            <p class="mt-2 text-[11px] leading-relaxed text-blue-700">
                                Yang discan tetap barangnya satu per satu — paketnya sendiri tidak punya barcode.
                            </p>
                        </div>
                    @endif

                    <ul class="scrollbar-thin max-h-[28rem] divide-y divide-ink-100 overflow-y-auto">
                        @foreach ($outbound->items as $item)
                            <li class="px-4 py-3 transition" x-data="{ itemId: {{ $item->id }} }"
                                :class="itemProgress(itemId).completed && 'bg-green-50'">
                                <div class="flex items-start gap-3">
                                    <span class="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full transition"
                                          :class="itemProgress(itemId).completed ? 'bg-green-600 text-white' : 'bg-control text-ink-400'">
                                        <template x-if="itemProgress(itemId).completed"><x-icon name="check" class="h-3 w-3" /></template>
                                    </span>

                                    <div class="min-w-0 flex-1">
                                        <x-ui.sku :value="$item->product->sku" />
                                        <p class="mt-1 text-sm font-medium text-ink-950">{{ $item->product->name }}</p>
                                        @if ($item->product->barcode)
                                            <p class="font-mono text-[11px] text-ink-400">Barcode {{ $item->product->barcode }}</p>
                                        @else
                                            <p class="text-[11px] text-ink-400">Tanpa barcode — scan pakai SKU</p>
                                        @endif

                                        <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-control">
                                            <div class="h-full rounded-full transition-all duration-300"
                                                 :class="itemProgress(itemId).completed ? 'bg-green-500' : 'bg-blue-500'"
                                                 :style="`width: ${Math.min(100, itemProgress(itemId).scanned / {{ max($item->quantity, 1) }} * 100)}%`"></div>
                                        </div>
                                    </div>

                                    <div class="shrink-0 text-right">
                                        <p class="text-sm font-semibold text-ink-950">
                                            <span x-text="itemProgress(itemId).scanned"></span><span class="text-ink-300">/{{ $item->quantity }}</span>
                                        </p>
                                        <p class="text-[11px]"
                                           :class="itemProgress(itemId).completed ? 'text-green-700' : 'text-ink-400'"
                                           x-text="itemProgress(itemId).completed
                                                ? 'selesai'
                                                : `sisa ${ {{ $item->quantity }} - itemProgress(itemId).scanned}`"></p>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>

        {{-- Aksi sekunder, sengaja dijauhkan dari alur utama --}}
        <div class="mt-6 flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-xs text-ink-500">
            <a href="{{ route('admin.outbounds.show', $outbound) }}" class="text-ink-700 underline-offset-4 hover:underline">
                Lihat detail dokumen
            </a>

            @if ($outbound->isEditable())
                <form method="POST" action="{{ route('admin.outbounds.scan.reset', $outbound) }}">
                    @csrf
                    <button type="submit" class="text-ink-700 underline-offset-4 hover:text-ink-950 hover:underline">
                        Ulangi scan dari awal
                    </button>
                </form>
            @endif

            @can('outbounds.create')
                <a href="{{ route('admin.outbounds.marketplace') }}" class="text-ink-700 underline-offset-4 hover:underline">
                    Scan pesanan lain
                </a>
            @endcan
        </div>
    </div>
</x-app-layout>
