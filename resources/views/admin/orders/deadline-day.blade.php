{{--
    Rincian satu tanggal batas kirim di Tenggat PO: barang dan jumlahnya,
    stok gudang, sebaran toko / kurir / lama PO, dan daftar resinya.
--}}
@php
    use App\Http\Controllers\Admin\PoDeadlineController as Deadlines;
    use App\Support\PackingList;

    $num = fn ($value) => number_format((int) $value, 0, ',', '.');
    $carry = array_filter(request()->only(['jenis', 'tahap', 'store', 'courier']), fn ($value) => filled($value));
    $dayUrl = fn ($day, array $set = []) => route('admin.orders.deadlines.day', ['date' => $day->format('Y-m-d')] + $set + $carry);
    $today = $now->copy()->startOfDay();
    $diff = (int) $today->diffInDays($day, false);
    $relative = match (true) {
        $diff === 0 => 'Hari ini',
        $diff === 1 => 'Besok',
        $diff === -1 => 'Kemarin',
        $diff < 0 => abs($diff).' hari lalu',
        default => $diff.' hari lagi',
    };
    $short = $products->filter(fn ($row) => $row['stock'] !== null && $row['remaining'] > $row['stock'])->count();
    $unknown = $products->where('matched', false)->count();
    $percent = $totals['ordered'] > 0 ? (int) floor($totals['scanned'] * 100 / $totals['ordered']) : 0;
@endphp

<x-app-layout :title="'Tenggat '.$day->translatedFormat('d M Y')">
    <x-ui.page-header :title="'Batas kirim '.$day->translatedFormat('l, d M Y')"
                      :back="route('admin.orders.deadlines', $carry + ['bulan' => $day->format('Y-m')])"
                      :status="$relative" :status-variant="$diff < 0 ? 'danger' : ($diff === 0 ? 'warning' : 'neutral')"
                      :subtitle="($kind === 'po' ? 'Resi Pre Order' : 'Semua resi').' yang belum dikirim dan harus sudah dikirim pada tanggal ini.'">
        <x-slot name="actions">
            <x-ui.button :href="$dayUrl($day->copy()->subDay())" variant="secondary" icon="chevron-left" title="Tanggal sebelumnya">Sebelumnya</x-ui.button>
            <x-ui.button :href="$dayUrl($day->copy()->addDay())" variant="secondary" title="Tanggal berikutnya">Berikutnya <x-icon name="chevron-right" class="h-4 w-4" /></x-ui.button>
        </x-slot>
    </x-ui.page-header>

    <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4" data-day-summary>
        <x-ui.stat-card label="Resi" :value="$num($summary['total'])" icon="document" accent
                        :hint="$summary['priority'] ? $num($summary['priority']).' prioritas' : 'Belum dikirim'" />
        <x-ui.stat-card label="Unit harus dikemas" :value="$num($totals['remaining'])" icon="box"
                        :hint="$num($totals['ordered']).' unit total · '.$percent.'% sudah QC'" />
        <x-ui.stat-card label="Siap dikemas" :value="$num($summary['ready'])" icon="check"
                        :hint="$num($summary['waiting']).' menunggu stok · '.$num($summary['done']).' sudah QC'"
                        info="Siap dikemas: Belum QC dan semua barangnya kebagian stok. Menunggu stok: stok belum cukup atau SKU belum dikenal. Sudah QC: tinggal discan di Serah Terima Paket." />
        <x-ui.stat-card label="Lewat batas" :value="$num($summary['overdue'])" icon="clock"
                        :hint="$summary['overdue'] ? 'Jam batas kirimnya sudah lewat' : 'Belum ada yang lewat'"
                        :class="$summary['overdue'] > 0 ? '!border-rose-200 !bg-rose-50' : ''" />
    </div>

    @if ($summary['waiting'] > 0 && $short === 0 && $unknown === 0)
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" data-day-waiting>
            <p class="flex items-center gap-2 font-medium"><x-icon name="info" class="h-4 w-4" />
                {{ $num($summary['waiting']) }} resi menunggu stok walaupun stok gudang terlihat cukup.
            </p>
            <p class="mt-0.5 text-xs text-amber-800">Stok dibagi dulu ke resi yang lebih mendesak (prioritas dan batas kirim lebih awal). Kerjakan tanggal sebelumnya dulu, atau tambah stok.</p>
        </div>
    @endif

    @if ($short > 0 || $unknown > 0)
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" data-day-warning>
            <p class="flex items-center gap-2 font-medium"><x-icon name="warning" class="h-4 w-4" />
                @if ($short > 0) {{ $num($short) }} barang stoknya kurang dari jumlah yang harus dikemas di tanggal ini. @endif
                @if ($unknown > 0) {{ $num($unknown) }} SKU belum terdaftar di master barang. @endif
            </p>
            <p class="mt-0.5 text-xs text-amber-800">Stok gudang juga dipakai resi tanggal lain; kekurangan di sini adalah perkiraan bila stok hanya untuk tanggal ini.</p>
        </div>
    @endif

    <div class="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="frappe-card lg:col-span-2" data-day-products>
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-ink-100 px-4 py-3">
                <div>
                    <h2 class="text-base font-semibold text-ink-950">Barang yang harus dikemas</h2>
                    <p class="mt-0.5 text-xs text-ink-500">{{ $num($products->count()) }} {{ $mode === PackingList::BY_SKU ? 'SKU di resi' : 'barang di rak' }}, sisa terbanyak di atas.</p>
                </div>
                <div class="inline-flex rounded-md border border-ink-200 bg-white p-0.5 text-xs">
                    @foreach ([PackingList::BY_PRODUCT => 'Per barang', PackingList::BY_SKU => 'Per SKU resi'] as $value => $label)
                        <a href="{{ $dayUrl($day, $value === PackingList::BY_PRODUCT ? [] : ['per' => $value]) }}"
                           data-hint="{{ $value === PackingList::BY_PRODUCT ? 'Paket bundling dipecah jadi isinya: yang diambil dari rak.' : 'Menurut SKU yang tertulis di resi; paket tetap satu baris.' }}"
                           @class(['rounded px-2.5 py-1 transition',
                               'bg-ink-100 font-medium text-ink-950' => $mode === $value,
                               'text-ink-600 hover:text-ink-950' => $mode !== $value])>{{ $label }}</a>
                    @endforeach
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th>Barang</th>
                            <th class="text-right">Resi</th>
                            <th class="text-right">Qty</th>
                            <th class="text-right" title="Unit yang sudah discan di QC Kemas">Sudah QC</th>
                            <th class="text-right" title="Unit yang belum discan di QC Kemas">Sisa</th>
                            <th class="text-right"><span class="inline-flex items-center gap-1">Stok gudang <x-ui.hint text="Stok barang saat ini di gudang (semua resi ikut memakainya)." /></span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($products as $row)
                            @php $isShort = $row['stock'] !== null && $row['remaining'] > $row['stock']; @endphp
                            <tr class="align-top">
                                <td>
                                    <div class="flex items-start gap-3">
                                        @if ($row['image_url'])
                                            <a href="{{ $row['image_url'] }}" target="_blank" rel="noopener"
                                               class="block h-10 w-10 shrink-0 overflow-hidden rounded-lg bg-ink-50 ring-1 ring-inset ring-ink-100">
                                                <img src="{{ $row['image_url'] }}" alt="{{ $row['name'] }}" loading="lazy" class="h-full w-full object-cover">
                                            </a>
                                        @else
                                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-ink-50 text-ink-300 ring-1 ring-inset ring-ink-100"><x-icon name="cube" class="h-4 w-4" /></span>
                                        @endif
                                        <div class="min-w-0">
                                            <span @class(['block font-mono text-[13px]', 'text-ink-950' => $row['matched'], 'text-amber-700' => ! $row['matched']])>{{ $row['sku'] }}</span>
                                            @if ($row['name'])<span class="block text-xs text-ink-500">{{ $row['name'] }}</span>@endif
                                            @unless ($row['matched'])<x-ui.badge variant="warning" class="mt-0.5">SKU belum terdaftar</x-ui.badge>@endunless
                                            @if ($row['from_bundle'])<x-ui.badge variant="purple" class="mt-0.5">Dari paket</x-ui.badge>@endif
                                        </div>
                                    </div>
                                </td>
                                <td class="text-right tabular-nums text-ink-600">{{ $num($row['orders']) }}</td>
                                <td class="text-right tabular-nums">{{ $num($row['ordered']) }}</td>
                                <td class="text-right tabular-nums text-emerald-700">{{ $row['scanned'] ? $num($row['scanned']) : '—' }}</td>
                                <td @class(['text-right text-base font-semibold tabular-nums', 'text-ink-950' => $row['remaining'] > 0, 'text-ink-400' => $row['remaining'] === 0])>{{ $num($row['remaining']) }}</td>
                                <td class="text-right tabular-nums">
                                    @if ($row['stock'] === null)
                                        <span class="text-ink-400">—</span>
                                    @else
                                        <span @class(['font-medium', 'text-rose-700' => $isShort, 'text-ink-950' => ! $isShort])>{{ $num($row['stock']) }}</span>
                                        @if ($isShort)
                                            <span class="block text-[11px] text-rose-700">kurang {{ $num($row['remaining'] - max(0, $row['stock'])) }}</span>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-10 text-center text-sm text-ink-500">Tidak ada barang yang harus dikemas di tanggal ini.</td></tr>
                        @endforelse
                    </tbody>
                    @if ($products->isNotEmpty())
                        <tbody>
                            <tr class="bg-ink-50 font-semibold">
                                <td>Total</td>
                                <td class="text-right tabular-nums">{{ $num($totals['orders']) }}</td>
                                <td class="text-right tabular-nums">{{ $num($totals['ordered']) }}</td>
                                <td class="text-right tabular-nums text-emerald-700">{{ $num($totals['scanned']) }}</td>
                                <td class="text-right tabular-nums">{{ $num($totals['remaining']) }}</td>
                                <td></td>
                            </tr>
                        </tbody>
                    @endif
                </table>
            </div>
        </div>

        <div class="space-y-4">
            @foreach ([['Per kurir', $couriers, 'truck'], ['Per toko', $stores, 'store']] as [$title, $rows, $icon])
                <x-ui.card :title="$title" padding="p-0">
                    <ul class="divide-y divide-ink-100 text-sm">
                        @forelse ($rows as $row)
                            <li class="flex items-center justify-between gap-3 px-4 py-2">
                                <span class="flex min-w-0 items-center gap-2 text-ink-700"><x-icon :name="$icon" class="h-3.5 w-3.5 shrink-0 text-ink-400" /><span class="truncate">{{ $row->label }}</span></span>
                                <span class="font-semibold tabular-nums text-ink-950">{{ $num($row->total) }}</span>
                            </li>
                        @empty
                            <li class="px-4 py-3 text-ink-500">—</li>
                        @endforelse
                    </ul>
                </x-ui.card>
            @endforeach
            <x-ui.card title="Per lama PO" padding="p-0">
                <ul class="divide-y divide-ink-100 text-sm">
                    @forelse ($leadTimes as $row)
                        <li class="flex items-center justify-between gap-3 px-4 py-2">
                            <span class="text-ink-700">{{ $row->po_days === null ? 'Belum diketahui' : ((int) $row->po_days === 0 ? 'Siap stok' : $row->po_days.' hari') }}</span>
                            <span class="font-semibold tabular-nums text-ink-950">{{ $num($row->total) }}</span>
                        </li>
                    @empty
                        <li class="px-4 py-3 text-ink-500">—</li>
                    @endforelse
                </ul>
            </x-ui.card>
        </div>
    </div>

    <div class="frappe-card" data-day-orders>
        <div class="border-b border-ink-100 px-4 py-3">
            <h2 class="text-base font-semibold text-ink-950">Resi</h2>
            <p class="mt-0.5 text-xs text-ink-500">{{ $num($orders->total()) }} resi, urut prioritas lalu jam batas kirim.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="erp-table">
                <thead>
                    <tr>
                        <th><x-ui.sort key="resi">No. Resi</x-ui.sort></th>
                        <th>Barang</th>
                        <th><x-ui.sort key="lama">Lama PO</x-ui.sort></th>
                        <th><x-ui.sort key="dibayar">Dibayar</x-ui.sort></th>
                        <th><x-ui.sort key="batas">Jam batas</x-ui.sort></th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($orders as $order)
                        @php $late = $order->ship_by && $order->ship_by->lt($now); @endphp
                        <tr>
                            <td class="align-top">
                                <a href="{{ route('admin.orders.index', ['search' => $order->tracking_number, 'search_by' => 'tracking_number', 'range' => 'semua']) }}"
                                   class="font-mono text-[13px] font-medium text-ink-950 hover:underline">{{ $order->tracking_number }}</a>
                                @if ($order->is_priority)<x-ui.badge variant="danger" class="ml-1">Prioritas</x-ui.badge>@endif
                                <p class="text-[11px] text-ink-400">{{ $order->order_number }}{{ $order->store_name ? ' · '.$order->store_name : '' }}{{ $order->courier ? ' · '.$order->courier : '' }}</p>
                            </td>
                            <td class="max-w-xs align-top text-sm">
                                @foreach ($order->items as $item)
                                    <p class="truncate">{{ $item->sku }} <span class="text-ink-500">× {{ $item->quantity }}</span></p>
                                @endforeach
                            </td>
                            <td class="align-top text-sm whitespace-nowrap">{{ $order->po_days === null ? '—' : ($order->po_days === 0 ? 'Siap stok' : $order->po_days.' hari') }}</td>
                            <td class="align-top text-sm whitespace-nowrap text-ink-600">{{ ($order->paid_at ?? $order->order_date)?->translatedFormat('d M H:i') ?? '—' }}</td>
                            <td class="align-top whitespace-nowrap">
                                <p @class(['text-sm font-medium', 'text-rose-700' => $late, 'text-ink-950' => ! $late])>{{ $order->ship_by?->format('H:i') }}</p>
                                <p @class(['text-[11px]', 'text-rose-700' => $late, 'text-ink-500' => ! $late])>{{ Deadlines::relative($order->ship_by, $now) }}</p>
                            </td>
                            <td class="align-top">
                                <x-ui.badge :variant="Deadlines::statusVariant($order)">{{ Deadlines::status($order) }}</x-ui.badge>
                                @if ($order->stock_note)<p class="mt-0.5 max-w-48 truncate text-[11px] text-ink-500" title="{{ $order->stock_note }}">{{ $order->stock_note }}</p>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-10 text-center text-sm text-ink-500">Tidak ada resi dengan batas kirim di tanggal ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pagination :paginator="$orders" unit="resi" />
    </div>
</x-app-layout>
