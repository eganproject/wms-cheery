{{--
    Harus dikirim menurut TikTok: unggah pesanan yang di TikTok diurutkan
    "prioritas pengiriman" dan dicentang sebagai yang harus dikirim hari ini.
--}}
<x-app-layout title="Harus Dikirim Menurut TikTok">
    <x-ui.page-header title="Harus Dikirim Menurut TikTok" :back="route('admin.orders.index')"
                      subtitle="Kunci batas kirim ke hari ini untuk pesanan yang di TikTok harus segera dikirim, dan temukan yang hitungan sistemnya meleset." />

    @if ($result)
        <div class="mb-5 space-y-4" data-priority-result>
            <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
                <x-ui.stat-card label="Dikunci hari ini" :value="$result['marked']" icon="clock" accent :hint="'Batas kirim '.$result['deadline']" />
                <x-ui.stat-card label="Beda dengan sistem" :value="$result['mismatch_count']" icon="warning" hint="Hitungan sistem lebih lambat" info="Resi yang menurut TikTok harus dikirim hari ini, tetapi menurut lama PO di sistem belum mendesak. Periksa lama PO barangnya." />
                <x-ui.stat-card label="Resi baru diimport" :value="$result['imported']" icon="import" hint="Belum ada di sistem sebelumnya" :href="route('admin.imports.show', $result['import_id'])" />
                <x-ui.stat-card label="Batal / sudah dikirim" :value="$result['closed_count']" icon="x-circle" hint="Perlu dicek di TikTok" />
            </div>

            @foreach ([
                ['mismatch', 'Beda dengan hitungan sistem', 'Batas kirim resi ini sudah dikunci ke hari ini. Betulkan lama PO barangnya supaya pesanan berikutnya terhitung benar.', 'amber'],
                ['closed', 'Di sistem sudah batal atau sudah dikirim', 'TikTok masih meminta pesanan ini dikirim. Pastikan statusnya di TikTok.', 'rose'],
            ] as [$key, $title, $desc, $tone])
                @if ($result[$key.'_count'] > 0)
                    <div class="frappe-card" data-priority-{{ $key }}>
                        <div class="border-b border-ink-100 px-4 py-3">
                            <p @class(['text-sm font-semibold', 'text-amber-800' => $tone === 'amber', 'text-rose-800' => $tone === 'rose'])>{{ $title }} · {{ $result[$key.'_count'] }}</p>
                            <p class="mt-0.5 text-xs text-ink-500">{{ $desc }}</p>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="erp-table">
                                <thead><tr><th>No. Resi</th><th>No. Pesanan</th><th>Barang</th><th>Dibayar</th><th>Keterangan</th></tr></thead>
                                <tbody>
                                    @foreach ($result[$key] as $row)
                                        <tr>
                                            <td class="font-mono text-xs">{{ $row['tracking'] }}</td>
                                            <td class="font-mono text-xs text-ink-500">{{ $row['order'] }}</td>
                                            <td class="text-xs">{{ $row['skus'] }}</td>
                                            <td class="whitespace-nowrap text-xs text-ink-500">{{ $row['paid'] }}</td>
                                            <td class="text-xs text-ink-700">{{ $row['note'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($result[$key.'_count'] > count($result[$key]))
                            <p class="border-t border-ink-100 px-4 py-2 text-xs text-ink-500">dan {{ $result[$key.'_count'] - count($result[$key]) }} resi lainnya.</p>
                        @endif
                    </div>
                @endif
            @endforeach

            @if ($result['absent_count'] > 0)
                <div class="rounded-xl border border-ink-200 bg-ink-50 p-4 text-xs text-ink-700" data-priority-absent>
                    <p class="font-semibold text-ink-900">{{ $result['absent_count'] }} pesanan belum punya resi</p>
                    <p class="mt-1">Belum "Atur Pengiriman" di TikTok, jadi resinya belum terbit dan belum bisa masuk sistem. Order ID:
                        <span class="font-mono">{{ implode(', ', array_slice($result['absent'], 0, 50)) }}{{ $result['absent_count'] > 50 ? ', …' : '' }}</span></p>
                </div>
            @endif
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            @can('imports.create')
                <form method="POST" action="{{ route('admin.orders.priority.store') }}" enctype="multipart/form-data" data-no-ajax
                      x-data="{ name: '' }">
                    @csrf
                    <x-ui.card title="Berkas dari TikTok" subtitle="Eksport pesanan yang dicentang (.xlsx / .csv). Maksimal 20 MB.">
                        <label class="flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed border-ink-200 px-6 py-10 text-center transition hover:border-ink-300 hover:bg-ink-50/50">
                            <input type="file" name="file" accept=".xlsx,.xls,.csv,.txt" class="sr-only" required
                                   @change="name = $event.target.files[0]?.name ?? ''">
                            <x-icon name="document" class="h-6 w-6 text-ink-500" />
                            <span class="mt-3 block text-sm font-semibold text-ink-950" x-show="! name">Klik untuk memilih berkas</span>
                            <span class="mt-3 block break-all font-mono text-sm font-semibold text-ink-950" x-show="name" x-cloak x-text="name"></span>
                        </label>
                        <x-input-error :messages="$errors->get('file')" class="mt-3" />
                        <div class="mt-4">
                            <x-ui.button type="submit" icon="check" class="w-full">Kunci batas kirim ke hari ini</x-ui.button>
                        </div>
                    </x-ui.card>
                </form>
            @endcan
        </div>

        <div class="space-y-4">
            <div class="rounded-2xl border border-ink-100 bg-ink-50/60 p-5">
                <p class="flex items-center gap-2 text-xs text-ink-400"><x-icon name="info" class="h-3.5 w-3.5" /> Cara pakai</p>
                <ol class="mt-3 space-y-2 text-xs leading-relaxed text-ink-600">
                    <li><span class="font-semibold text-ink-950">1.</span> Di TikTok, buka Pesanan → Untuk Dikirim, urutkan "prioritas pengiriman".</li>
                    <li><span class="font-semibold text-ink-950">2.</span> Centang pesanan teratas yang harus dikirim hari ini, lalu eksport yang dipilih.</li>
                    <li><span class="font-semibold text-ink-950">3.</span> Unggah di sini. Resi yang belum ada ikut diimport; resi yang sudah ada tidak diubah selain batas kirimnya.</li>
                    <li><span class="font-semibold text-ink-950">4.</span> Resi itu tampil paling atas di Pesanan dan Packing List Harian, ditandai Prioritas.</li>
                </ol>
            </div>
            <div class="frappe-card p-4 text-sm">
                <p class="text-xs text-ink-500">Saat ini belum dikirim</p>
                <p class="mt-1"><span class="font-semibold text-ink-950">{{ $openLocked }}</span> resi dikunci menurut TikTok</p>
                <p><span class="font-semibold text-amber-700">{{ $openMismatch }}</span> beda dengan hitungan sistem</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <x-ui.button :href="route('admin.orders.index', ['range' => 'semua', 'deadline' => 'mismatch', 'sort' => 'deadline'])" variant="secondary" size="sm">Lihat yang beda</x-ui.button>
                    <x-ui.button :href="route('admin.po-periods.index')" variant="secondary" size="sm">Atur Lama PO</x-ui.button>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
