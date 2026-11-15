{{--
    Scan Kain: satu-satunya jalan kain keluar dari stok, dan tempat cek ulang
    sisa roll. Scan QR roll → SKU kain dan sisanya langsung tampil.
--}}
<x-app-layout title="Scan Kain">
    <x-ui.page-header title="Scan Kain" icon="scan"
                      subtitle="Scan QR roll: SKU kain dan sisanya langsung tampil. Isi jumlah yang keluar atau ambil semua." />

    @if (! $canIssue && ! $canAdjust)
        <x-ui.card>
            <x-ui.empty-state icon="lock" title="Tidak ada akses scan"
                              description="Akun ini hanya bisa melihat stok kain. Minta akses Scan kain keluar atau Koreksi sisa roll ke admin." />
        </x-ui.card>
    @else
        <div x-data="fabricStation({
                urls: {
                    lookup: '{{ route('admin.fabric-scan.lookup') }}',
                    issue: '{{ route('admin.fabric-scan.issue') }}',
                    adjust: '{{ route('admin.fabric-scan.adjust') }}',
                },
                mode: '{{ $canIssue ? $mode : 'cek' }}',
                canIssue: {{ $canIssue ? 'true' : 'false' }},
                canAdjust: {{ $canAdjust ? 'true' : 'false' }},
                recent: {{ Js::from($recent) }},
             })"
             x-on:camera-scan.window="code = $event.detail.code; submit()"
             class="mx-auto w-full max-w-3xl space-y-5">

            @if ($canIssue && $canAdjust)
                <div class="flex rounded-xl bg-control p-1">
                    <button type="button" x-on:click="mode = 'keluar'"
                            class="flex-1 rounded-lg px-3 py-2 text-sm font-semibold transition"
                            :class="mode === 'keluar' ? 'bg-white text-ink-950 shadow-sm' : 'text-ink-500'">
                        Kain Keluar
                    </button>
                    <button type="button" x-on:click="mode = 'cek'"
                            class="flex-1 rounded-lg px-3 py-2 text-sm font-semibold transition"
                            :class="mode === 'cek' ? 'bg-white text-ink-950 shadow-sm' : 'text-ink-500'">
                        Cek &amp; Koreksi Roll
                    </button>
                </div>
            @endif

            <div class="frappe-card overflow-hidden">
                <div class="px-5 pt-6 text-center sm:px-10">
                    <span class="indicator-pill no-dot"
                          :class="isCheck ? 'bg-violet-50 text-violet-700' : 'bg-amber-50 text-amber-700'"
                          x-text="isCheck ? 'Mode cek: isi sisa hasil ukur' : 'Mode keluar: kain berkurang dari roll'"></span>
                    <h2 class="mt-3 text-xl font-semibold text-ink-950 sm:text-2xl" :class="roll && 'font-mono'" x-text="title"></h2>
                </div>

                <div class="p-5 sm:p-8 sm:pt-6">
                    <template x-if="! isCheck">
                        <div class="mb-4">
                            <label for="fabric-destination" class="form-label">Tujuan (penjahit / konveksi / keperluan)</label>
                            <input id="fabric-destination" x-model="destination" type="text" maxlength="120" list="fabric-destinations"
                                   placeholder="mis. Penjahit Bu Sri" class="form-control">
                            <datalist id="fabric-destinations">
                                @foreach ($destinations as $destination)
                                    <option value="{{ $destination }}"></option>
                                @endforeach
                            </datalist>
                            <p class="form-help">Diingat di perangkat ini untuk scan berikutnya.</p>
                        </div>
                    </template>

                    <div class="flex items-stretch gap-2">
                        @include('admin.partials.camera-scan', [
                            'scanTitle' => "roll ? 'Roll ' + roll.code + ' terbuka' : 'Scan QR roll kain'",
                        ])

                        <form data-no-ajax @submit.prevent="submit()" class="min-w-0 flex-1">
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex w-12 items-center justify-center text-ink-500">
                                    <x-icon name="qr-code" class="h-5 w-5" />
                                </span>
                                <input x-ref="code" x-model="code" type="text" autocomplete="off" autocapitalize="off"
                                       spellcheck="false" :disabled="busy" placeholder="Scan atau ketik kode roll…"
                                       class="block h-14 w-full rounded-lg pl-12 pr-24 font-mono text-lg text-ink-950 placeholder:font-sans placeholder:text-sm disabled:opacity-60">
                                <div class="absolute inset-y-0 right-0 flex items-center pr-2">
                                    <button type="submit" :disabled="busy" class="btn btn-primary h-10 px-4">
                                        <span x-show="! busy">Cari</span>
                                        <span x-show="busy" x-cloak class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>

                    {{-- Kartu roll yang terbuka. --}}
                    <template x-if="roll">
                        <div class="mt-4 rounded-lg border border-ink-100 bg-ink-50 p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-mono text-sm font-semibold text-ink-950" x-text="roll.sku"></p>
                                    <p class="mt-0.5 text-sm text-ink-600" x-text="roll.color ? `${roll.name} · ${roll.color}` : roll.name"></p>
                                    <p class="mt-1 text-[11px] text-ink-400"
                                       x-text="[`awal ${roll.initial_label}`, roll.lot ? `lot ${roll.lot}` : null, roll.location].filter(Boolean).join(' · ')"></p>
                                </div>
                                <div class="text-right">
                                    <p class="text-[11px] uppercase tracking-wide text-ink-400">Sisa tercatat</p>
                                    <p class="text-2xl font-semibold tabular-nums text-ink-950" x-text="roll.quantity_label"></p>
                                </div>
                            </div>

                            <div class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-end">
                                <div class="flex-1">
                                    <label for="fabric-quantity" class="form-label" x-text="`${quantityLabel} (${roll.unit})`"></label>
                                    <input id="fabric-quantity" x-ref="quantity" x-model="quantity" type="text" inputmode="decimal" autocomplete="off"
                                           x-on:keydown.enter.prevent="quantityEnter()" placeholder="0"
                                           class="h-12 w-full rounded-lg bg-white text-center text-lg font-semibold tabular-nums text-ink-950">
                                </div>
                                <div class="flex gap-2">
                                    <template x-if="! isCheck">
                                        <button type="button" x-on:click="takeAll()" :disabled="busy || roll.empty"
                                                class="btn btn-default h-12 bg-white px-4">
                                            <span x-text="`Ambil Semua (${roll.quantity_label})`"></span>
                                        </button>
                                    </template>
                                    <button type="button" x-on:click="save()" :disabled="busy" class="btn btn-primary h-12 px-5">
                                        <x-icon name="check" class="h-4 w-4" />
                                        <span x-text="isCheck ? 'Simpan Koreksi' : 'Simpan'"></span>
                                    </button>
                                </div>
                            </div>

                            <div class="mt-3 flex items-center gap-2">
                                <input x-model="note" type="text" maxlength="255" placeholder="Catatan (opsional)" class="form-control h-10 flex-1 bg-white text-sm">
                                <button type="button" x-on:click="cancel()" class="btn btn-ghost">Batal</button>
                            </div>
                        </div>
                    </template>

                    {{-- Hasil terakhir. --}}
                    <template x-if="feedback">
                        <div class="mt-4 flex items-start gap-3 rounded-lg p-3.5"
                             :class="feedback.type === 'success' ? 'bg-green-100' : 'bg-red-100'">
                            <template x-if="feedback.type === 'success'">
                                <x-icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0 text-green-700" />
                            </template>
                            <template x-if="feedback.type === 'error'">
                                <x-icon name="x-circle" class="mt-0.5 h-4 w-4 shrink-0 text-red-600" />
                            </template>
                            <p class="flex-1 text-sm" :class="feedback.type === 'success' ? 'text-green-800' : 'text-red-700'" x-text="feedback.message"></p>
                            <span class="text-[11px] text-ink-400" x-text="feedback.time"></span>
                        </div>
                    </template>
                </div>
            </div>

            <x-ui.card title="Kain Keluar Saya Hari Ini" padding="p-0">
                <x-slot name="actions">
                    <x-ui.button :href="route('admin.fabric-issues.index')" variant="ghost" size="sm">Semua kain keluar</x-ui.button>
                </x-slot>
                <template x-if="recent.length === 0">
                    <p class="px-5 py-6 text-center text-sm text-ink-400">Belum ada kain keluar hari ini.</p>
                </template>
                <ul class="divide-y divide-ink-50">
                    <template x-for="item in recent" :key="item.id">
                        <li class="flex items-center gap-3 px-4 py-2.5 sm:px-5">
                            <span class="w-12 text-xs tabular-nums text-ink-400" x-text="item.time"></span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-mono text-sm font-semibold text-ink-950" x-text="item.code"></p>
                                <p class="truncate text-xs text-ink-500" x-text="[item.name, item.destination ? `ke ${item.destination}` : null].filter(Boolean).join(' · ')"></p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-semibold tabular-nums" :class="item.cancelled ? 'text-ink-400 line-through' : 'text-rose-600'" x-text="`-${item.quantity_label}`"></p>
                                <p class="text-[11px] text-ink-400" x-text="`sisa ${item.balance_label}`"></p>
                            </div>
                        </li>
                    </template>
                </ul>
            </x-ui.card>
        </div>
    @endif
</x-app-layout>
