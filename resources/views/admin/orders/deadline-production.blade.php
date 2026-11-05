{{--
    Rencana produksi PO: kebutuhan barang untuk tanggal batas kirim yang
    dicentang di Tenggat PO, berikut stok dan yang harus diproduksi.
--}}
@php
    use App\Http\Controllers\Admin\PoDeadlineController as Deadlines;
    use App\Support\PackingList;

    $num = fn ($value) => number_format((int) $value, 0, ',', '.');
    $carry = array_filter(request()->only(['jenis', 'tahap', 'store', 'courier', 'per']), fn ($value) => filled($value));
    $dates = $plan->dates;
    $keys = $dates->map(fn ($date) => $date->format('Y-m-d'));
    $planUrl = fn ($list, array $set = []) => route('admin.orders.deadlines.production',
        array_filter(array_merge($carry, ['tanggal' => collect($list)->map(fn ($d) => is_string($d) ? $d : $d->format('Y-m-d'))->implode(',')], $set), fn ($v) => filled($v)));
    $range = function (int $from, int $days) use ($now) {
        return collect(range(0, $days - 1))->map(fn ($i) => $now->copy()->startOfDay()->addDays($from + $i));
    };
    $endOfMonth = (int) $now->copy()->startOfDay()->diffInDays($now->copy()->endOfMonth()->startOfDay()) + 1;
    $quick = [
        'Hari ini s/d 7 hari' => $range(0, 7),
        '14 hari ke depan' => $range(0, 14),
        'Sisa bulan ini' => $range(0, $endOfMonth),
        'Bulan depan' => collect(range(0, $now->copy()->addMonthNoOverflow()->daysInMonth - 1))->map(fn ($i) => $now->copy()->addMonthNoOverflow()->startOfMonth()->addDays($i)),
    ];
    $showDays = $dates->count() <= 10;
    $totals = $plan->totals;
    $exportQuery = array_merge($carry, ['tanggal' => $keys->implode(',')]);
@endphp

<x-app-layout title="Rencana Produksi PO">
    <x-ui.page-header title="Rencana Produksi PO"
                      :back="route('admin.orders.deadlines', array_diff_key($carry, ['per' => 1]))"
                      subtitle="Berapa unit tiap barang yang dibutuhkan untuk tanggal batas kirim yang dipilih, dan berapa yang harus diproduksi karena stok tidak cukup.">
        @if ($dates->isNotEmpty())
            <x-slot name="actions">
                <x-ui.button :href="route('admin.orders.deadlines.production.excel', $exportQuery)" variant="secondary" icon="document" data-no-ajax>Export Excel</x-ui.button>
                <x-ui.button :href="route('admin.orders.deadlines.production.pdf', $exportQuery)" variant="secondary" icon="printer" data-no-ajax>Export PDF</x-ui.button>
            </x-slot>
        @endif
    </x-ui.page-header>

    {{-- Tanggal yang dipilih --}}
    <div class="frappe-card mb-4 space-y-3 px-4 py-3 text-sm" data-plan-dates>
        <div class="flex flex-col gap-1.5 sm:flex-row sm:items-start">
            <span class="w-24 shrink-0 pt-1 text-xs text-ink-500">Tanggal dipilih</span>
            <div class="flex flex-wrap gap-1.5">
                @forelse ($dates as $date)
                    <a href="{{ $planUrl($keys->reject(fn ($key) => $key === $date->format('Y-m-d'))) }}" title="Hapus tanggal ini"
                       class="inline-flex items-center gap-1 rounded-md border border-brand-500 bg-brand-50 px-2 py-1 text-xs font-medium text-brand-800 hover:bg-brand-100">
                        {{ $date->translatedFormat('D, d M') }} <x-icon name="close" class="h-3 w-3" />
                    </a>
                @empty
                    <span class="pt-1 text-ink-500">Belum ada. Centang tanggal di kalender Tenggat PO, atau pilih di bawah.</span>
                @endforelse
            </div>
        </div>
        <div class="flex flex-col gap-1.5 sm:flex-row sm:items-center">
            <span class="w-24 shrink-0 text-xs text-ink-500">Pilih cepat</span>
            <div class="flex flex-wrap gap-1.5">
                @foreach ($quick as $label => $list)
                    <a href="{{ $planUrl($list) }}" class="inline-flex items-center rounded-md border border-ink-200 bg-white px-2.5 py-1 text-xs text-ink-600 hover:border-ink-300 hover:text-ink-950">{{ $label }}</a>
                @endforeach
            </div>
        </div>
        <form method="GET" action="{{ route('admin.orders.deadlines.production') }}" class="flex flex-col gap-1.5 sm:flex-row sm:items-center">
            <span class="w-24 shrink-0 text-xs text-ink-500">Rentang</span>
            @foreach ($carry as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <div class="flex flex-wrap items-center gap-2">
                <input type="date" name="dari" class="form-control w-auto" value="{{ $dates->first()?->format('Y-m-d') }}" aria-label="Dari tanggal" required>
                <span class="text-ink-500">s/d</span>
                <input type="date" name="sampai" class="form-control w-auto" value="{{ $dates->last()?->format('Y-m-d') }}" aria-label="Sampai tanggal" required>
                <x-ui.button type="submit" variant="secondary">Terapkan</x-ui.button>
            </div>
        </form>
    </div>

    @if ($dates->isNotEmpty())
        <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4" data-plan-totals>
            <x-ui.stat-card label="Resi" :value="$num($totals['orders'])" icon="document" accent :hint="$dates->count().' tanggal batas kirim'" />
            <x-ui.stat-card label="Total dibutuhkan" :value="$num($totals['needed'])" icon="box" :hint="$num($totals['products']).' barang'" />
            <x-ui.stat-card label="Tertutup stok" :value="$num($totals['covered'])" icon="check" hint="Bisa dikirim dari stok gudang" />
            <x-ui.stat-card label="Harus diproduksi" :value="$num($totals['short'])" icon="sliders"
                            :hint="$totals['short_products'] ? $num($totals['short_products']).' barang kurang' : 'Stok cukup semua'"
                            :class="$totals['short'] > 0 ? '!border-rose-200 !bg-rose-50' : ''"
                            info="Kebutuhan tanggal terpilih dikurangi stok gudang yang tersisa setelah dipakai resi yang batas kirimnya lebih awal." />
        </div>

        @if ($totals['earlier_short'] > 0)
            <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" data-plan-earlier>
                <p class="flex items-center gap-2 font-medium"><x-icon name="warning" class="h-4 w-4" />
                    Tanggal lain yang lebih awal (tidak dipilih) juga kekurangan {{ $num($totals['earlier_short']) }} unit.</p>
                <p class="mt-0.5 text-xs text-amber-800">Resi itu memakai stok lebih dulu. Sertakan tanggalnya bila produksinya ikut direncanakan sekarang.</p>
            </div>
        @endif

        <div class="frappe-card" data-plan-table>
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-ink-100 px-4 py-3">
                <div>
                    <h2 class="text-base font-semibold text-ink-950">Kebutuhan per barang</h2>
                    <p class="mt-0.5 text-xs text-ink-500">Yang harus diproduksi paling banyak di atas. @unless ($showDays) Rincian per tanggal ada di Excel. @endunless</p>
                </div>
                <div class="inline-flex rounded-md border border-ink-200 bg-white p-0.5 text-xs">
                    @foreach ([PackingList::BY_PRODUCT => 'Per barang', PackingList::BY_SKU => 'Per SKU resi'] as $value => $label)
                        <a href="{{ $planUrl($keys, ['per' => $value === PackingList::BY_PRODUCT ? null : $value]) }}"
                           @class(['rounded px-2.5 py-1 transition', 'bg-ink-100 font-medium text-ink-950' => $mode === $value, 'text-ink-600 hover:text-ink-950' => $mode !== $value])>{{ $label }}</a>
                    @endforeach
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th>Barang</th>
                            @if ($showDays)
                                @foreach ($dates as $date)
                                    <th class="text-right whitespace-nowrap">{{ $date->translatedFormat('d M') }}</th>
                                @endforeach
                            @endif
                            <th class="text-right">Total</th>
                            <th class="text-right"><span class="inline-flex items-center gap-1">Stok <x-ui.hint text="Stok gudang saat ini, dikurangi yang sudah discan di QC Kemas tetapi belum diserahkan ke kurir." /></span></th>
                            <th class="text-right"><span class="inline-flex items-center gap-1">Tgl lain <x-ui.hint text="Dibutuhkan resi di tanggal batas kirim lain yang lebih awal (tidak dipilih). Resi itu memakai stok lebih dulu." /></span></th>
                            <th class="text-right">Tertutup stok</th>
                            <th class="text-right">Harus diproduksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($plan->rows as $row)
                            <tr class="align-top">
                                <td>
                                    <div class="flex items-start gap-3">
                                        @if ($row['image_url'])
                                            <img src="{{ $row['image_url'] }}" alt="" loading="lazy" class="h-10 w-10 shrink-0 rounded-lg object-cover ring-1 ring-inset ring-ink-100">
                                        @else
                                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-ink-50 text-ink-300 ring-1 ring-inset ring-ink-100"><x-icon name="cube" class="h-4 w-4" /></span>
                                        @endif
                                        <div class="min-w-0">
                                            <span @class(['block font-mono text-[13px]', 'text-ink-950' => $row['matched'], 'text-amber-700' => ! $row['matched']])>{{ $row['sku'] }}</span>
                                            @if ($row['name'])<span class="block text-xs text-ink-500">{{ $row['name'] }}</span>@endif
                                            @unless ($row['matched'])<x-ui.badge variant="warning" class="mt-0.5">SKU belum terdaftar</x-ui.badge>@endunless
                                            @if ($row['from_bundle'])<x-ui.badge variant="purple" class="mt-0.5">Termasuk isi paket</x-ui.badge>@endif
                                        </div>
                                    </div>
                                </td>
                                @if ($showDays)
                                    @foreach ($keys as $key)
                                        <td class="text-right tabular-nums">
                                            @if ($value = $row['days'][$key] ?? 0)
                                                {{ $num($value) }}
                                                @if ($row['short_days'][$key] ?? 0)<span class="block text-[11px] text-rose-700">−{{ $num($row['short_days'][$key]) }}</span>@endif
                                            @else
                                                <span class="text-ink-300">—</span>
                                            @endif
                                        </td>
                                    @endforeach
                                @endif
                                <td class="text-right font-semibold tabular-nums text-ink-950">{{ $num($row['needed']) }}</td>
                                <td class="text-right tabular-nums">{{ $row['stock'] === null ? '—' : $num($row['available']) }}</td>
                                <td class="text-right tabular-nums text-ink-600">{{ $row['earlier'] ? $num($row['earlier']) : '—' }}</td>
                                <td class="text-right tabular-nums text-emerald-700">{{ $row['covered'] ? $num($row['covered']) : '—' }}</td>
                                <td @class(['text-right text-base font-semibold tabular-nums', 'text-rose-700' => $row['short'] > 0, 'text-ink-400' => $row['short'] === 0])>{{ $num($row['short']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ 6 + ($showDays ? $dates->count() : 0) }}" class="py-10 text-center text-sm text-ink-500">Tidak ada resi yang belum dikirim di tanggal yang dipilih.</td></tr>
                        @endforelse
                        @if ($plan->rows->isNotEmpty())
                            <tr class="bg-ink-50 font-semibold">
                                <td>Total</td>
                                @if ($showDays)
                                    @foreach ($keys as $key)
                                        <td class="text-right tabular-nums">{{ $num($plan->rows->sum(fn ($row) => $row['days'][$key] ?? 0)) }}</td>
                                    @endforeach
                                @endif
                                <td class="text-right tabular-nums">{{ $num($totals['needed']) }}</td>
                                <td></td>
                                <td class="text-right tabular-nums">{{ $num($plan->rows->sum('earlier')) }}</td>
                                <td class="text-right tabular-nums text-emerald-700">{{ $num($totals['covered']) }}</td>
                                <td class="text-right tabular-nums text-rose-700">{{ $num($totals['short']) }}</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
        <p class="mt-3 text-xs text-ink-500">Hanya resi yang belum dikirim dan sudah punya batas kirim. Angka merah kecil di kolom tanggal = kekurangan untuk tanggal itu.</p>
    @endif
</x-app-layout>
