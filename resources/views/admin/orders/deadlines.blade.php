{{--
    Tenggat PO: jumlah resi yang masih harus diproses menurut batas kirimnya,
    supaya tim tahu mana yang dikerjakan lebih dulu. Hanya membaca.
--}}
@php
    use App\Http\Controllers\Admin\PoDeadlineController as Deadlines;

    $num = fn ($value) => number_format((int) $value, 0, ',', '.');
    $keep = fn (array $except, array $set = []) => array_filter(
        array_merge(request()->except(array_merge($except, ['page'])), $set),
        fn ($value) => filled($value),
    );
    // Memilih kelompok, tanggal, atau lama PO saling menggantikan.
    $pick = fn (array $set) => route('admin.orders.deadlines', $keep(['kelompok', 'tanggal', 'lama'], $set)).'#daftar-resi';

    $pill = 'inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs whitespace-nowrap transition';
    $pillOn = 'border-brand-500 bg-brand-50 font-medium text-brand-800';
    $pillOff = 'border-ink-200 bg-white text-ink-600 hover:border-ink-300 hover:text-ink-950';

    $tone = [
        'overdue' => ['bar' => 'bg-rose-500', 'text' => 'text-rose-700'],
        'today' => ['bar' => 'bg-amber-500', 'text' => 'text-amber-700'],
        'tomorrow' => ['bar' => 'bg-yellow-400', 'text' => 'text-yellow-700'],
        'soon' => ['bar' => 'bg-sky-500', 'text' => 'text-sky-700'],
        'week' => ['bar' => 'bg-sky-300', 'text' => 'text-sky-700'],
        'later' => ['bar' => 'bg-emerald-400', 'text' => 'text-emerald-700'],
        'missing' => ['bar' => 'bg-ink-300', 'text' => 'text-ink-600'],
    ];

    $today = $now->copy()->startOfDay();
    $dayLabel = function ($day) use ($today) {
        $diff = (int) $today->diffInDays($day->copy()->startOfDay(), false);

        return match (true) {
            $diff === 0 => 'Hari ini',
            $diff === 1 => 'Besok',
            $diff === -1 => 'Kemarin',
            $diff < 0 => abs($diff).' hari lalu',
            default => $diff.' hari lagi',
        };
    };

    $activeLabel = match (true) {
        $bucket !== '' => Deadlines::BUCKETS[$bucket],
        $date !== null => 'Batas kirim '.$date->translatedFormat('l, d M Y'),
        $poDays !== false => $poDays === null ? 'Lama PO belum diketahui' : 'Lama PO '.$poDays.' hari',
        default => null,
    };
    $maxBucket = max(1, $buckets->max('total'));
@endphp

<x-app-layout title="Tenggat PO">
    <x-ui.page-header title="Tenggat PO"
                      subtitle="Berapa resi yang masih harus diproses, dikelompokkan menurut batas kirimnya. Kerjakan dari yang paling kiri.">
        <x-slot name="actions">
            <x-ui.button :href="route('admin.orders.packing')" variant="secondary" icon="list">Packing List Harian</x-ui.button>
            <x-ui.button :href="route('admin.po-periods.index')" variant="secondary" icon="calendar">Lama PO</x-ui.button>
        </x-slot>
    </x-ui.page-header>

    {{-- Saringan --}}
    <div class="frappe-card mb-4 space-y-2 px-4 py-3 text-sm" data-deadline-filters>
        <div class="flex flex-col gap-1.5 sm:flex-row sm:items-center">
            <span class="w-20 shrink-0 text-xs text-ink-500">Jenis</span>
            <div class="flex flex-wrap gap-1.5">
                @foreach (Deadlines::KINDS as $value => $label)
                    <a href="{{ route('admin.orders.deadlines', $keep(['jenis'], ['jenis' => $value === 'po' ? null : $value])) }}"
                       data-hint-title="{{ $label }}"
                       data-hint="{{ $value === 'po' ? 'Resi yang ditandai Pre-order di berkas TikTok, atau produknya punya lama PO lebih dari 0 hari.' : 'Semua resi yang belum dikirim, termasuk pesanan Normal (ready stock).' }}"
                       @class([$pill, $pillOn => $kind === $value, $pillOff => $kind !== $value])>{{ $label }}</a>
                @endforeach
            </div>
        </div>
        <div class="flex flex-col gap-1.5 sm:flex-row sm:items-center">
            <span class="w-20 shrink-0 text-xs text-ink-500">Tahap</span>
            <div class="flex flex-wrap gap-1.5">
                @foreach (Deadlines::STAGES as $value => $label)
                    <a href="{{ route('admin.orders.deadlines', $keep(['tahap'], ['tahap' => $value])) }}"
                       @class([$pill, $pillOn => $stage === $value, $pillOff => $stage !== $value])>{{ $label }}</a>
                @endforeach
            </div>
        </div>
        @if ($stores->count() > 1 || $couriers->count() > 1 || request()->hasAny(['store', 'courier']))
            <form method="GET" action="{{ route('admin.orders.deadlines') }}" class="flex flex-col gap-1.5 sm:flex-row sm:items-center">
                <span class="w-20 shrink-0 text-xs text-ink-500">Toko / kurir</span>
                @foreach ($keep(['store', 'courier']) as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
                <div class="flex flex-wrap gap-2">
                    <select name="store" class="form-control w-auto min-w-40" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()" aria-label="Toko">
                        <option value="">Semua toko</option>
                        @foreach ($stores as $store)
                            <option value="{{ $store }}" @selected(request('store') === $store)>{{ $store }}</option>
                        @endforeach
                    </select>
                    <select name="courier" class="form-control w-auto min-w-40" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()" aria-label="Kurir">
                        <option value="">Semua kurir</option>
                        @foreach ($couriers as $courier)
                            <option value="{{ $courier }}" @selected(request('courier') === $courier)>{{ $courier }}</option>
                        @endforeach
                    </select>
                </div>
            </form>
        @endif
    </div>

    {{-- Jumlah per kelompok batas kirim --}}
    <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-7" data-deadline-buckets>
        @foreach ($buckets as $row)
            <a href="{{ $bucket === $row['key'] ? $pick([]) : $pick(['kelompok' => $row['key']]) }}"
               data-bucket="{{ $row['key'] }}"
               data-hint-title="{{ $row['label'] }}"
               data-hint="{{ match ($row['key']) {
                   'overdue' => 'Batas kirimnya sudah lewat dan resi belum dikirim. Kerjakan paling dulu.',
                   'today' => 'Harus dikirim paling lambat hari ini pukul 23.59.',
                   'tomorrow' => 'Harus dikirim paling lambat besok.',
                   'soon' => 'Batas kirim lusa sampai 3 hari lagi.',
                   'week' => 'Batas kirim 4 sampai 7 hari lagi.',
                   'later' => 'Batas kirim lebih dari seminggu lagi.',
                   default => 'Belum bisa dihitung: produknya belum punya lama PO di menu Lama PO.',
               } }}"
               @class(['frappe-card relative block min-w-0 overflow-hidden px-4 pb-3 pt-4 transition-colors hover:border-ink-200 hover:bg-ink-50',
                   'ring-2 ring-brand-500' => $bucket === $row['key']])>
                <span class="absolute inset-x-0 top-0 h-1 {{ $tone[$row['key']]['bar'] }}"></span>
                <p class="truncate text-sm text-ink-500">{{ $row['label'] }}</p>
                <p @class(['mt-1 text-2xl font-semibold sm:text-[26px] sm:leading-8',
                    $tone[$row['key']]['text'] => $row['total'] > 0 && in_array($row['key'], ['overdue', 'today'], true),
                    'text-ink-950' => ! ($row['total'] > 0 && in_array($row['key'], ['overdue', 'today'], true))])>{{ $num($row['total']) }}</p>
                <p class="mt-0.5 truncate text-xs text-ink-500" title="Siap dikemas · menunggu stok · sudah QC">
                    @if ($row['total'] > 0)
                        {{ $num($row['ready']) }} siap · {{ $num($row['waiting']) }} tunggu{{ $row['done'] ? ' · '.$num($row['done']).' QC' : '' }}
                    @else
                        resi
                    @endif
                </p>
            </a>
        @endforeach
    </div>

    <div class="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <x-ui.card class="lg:col-span-2" padding="p-0" :title="$view === 'kalender' ? 'Kalender batas kirim' : 'Per tanggal batas kirim'"
                   :subtitle="$num($total).' resi '.($kind === 'po' ? 'PO ' : '').'belum dikirim. Klik tanggal untuk melihat resinya.'">
            <x-slot name="actions">
                @if ($view === 'kalender')
                    <div class="flex items-center gap-1" data-calendar-nav>
                        <a href="{{ route('admin.orders.deadlines', $keep(['bulan'], ['bulan' => $month->copy()->subMonth()->format('Y-m')])) }}"
                           class="btn btn-ghost btn-icon" title="Bulan sebelumnya"><x-icon name="chevron-left" class="h-4 w-4" /></a>
                        <span class="min-w-28 text-center text-sm font-medium text-ink-950">{{ $month->translatedFormat('F Y') }}</span>
                        <a href="{{ route('admin.orders.deadlines', $keep(['bulan'], ['bulan' => $month->copy()->addMonth()->format('Y-m')])) }}"
                           class="btn btn-ghost btn-icon" title="Bulan berikutnya"><x-icon name="chevron-right" class="h-4 w-4" /></a>
                        @unless ($month->isSameMonth($now))
                            <a href="{{ route('admin.orders.deadlines', $keep(['bulan'])) }}" class="btn btn-default btn-xs">Bulan ini</a>
                        @endunless
                    </div>
                @endif
                {{-- Dua cara melihat data yang sama di halaman ini, bukan menu lain. --}}
                <div class="inline-flex rounded-md border border-ink-200 bg-white p-0.5 text-xs" data-view-switch>
                    @foreach (['kalender' => 'Kalender', 'daftar' => 'Daftar'] as $value => $label)
                        <a href="{{ route('admin.orders.deadlines', $keep(['tampilan'], ['tampilan' => $value === 'kalender' ? null : $value])) }}"
                           @class(['inline-flex items-center gap-1 rounded px-2.5 py-1 transition',
                               'bg-ink-100 font-medium text-ink-950' => $view === $value,
                               'text-ink-600 hover:text-ink-950' => $view !== $value])>
                            <x-icon :name="$value === 'kalender' ? 'calendar-days' : 'list'" class="h-3.5 w-3.5" /> {{ $label }}
                        </a>
                    @endforeach
                </div>
            </x-slot>

            @if ($view === 'kalender')
                @php
                    $byDay = $days->keyBy(fn ($row) => $row['day']->format('Y-m-d'));
                    $gridStart = $month->copy()->startOfWeek(\Illuminate\Support\Carbon::MONDAY);
                    $gridEnd = $month->copy()->endOfMonth()->endOfWeek(\Illuminate\Support\Carbon::SUNDAY)->startOfDay();
                    $inMonth = $days->filter(fn ($row) => $row['day']->isSameMonth($month));
                    $maxDay = max(1, $inMonth->max('total') ?? 0);
                    $before = $days->filter(fn ($row) => $row['day']->lt($gridStart))->sum('total');
                    $after = $days->filter(fn ($row) => $row['day']->gt($gridEnd))->sum('total');
                @endphp
                <div class="p-3 sm:p-4" data-deadline-calendar>
                    @if ($before > 0 || $after > 0)
                        <div class="mb-3 flex flex-wrap gap-2 text-xs">
                            @if ($before > 0)
                                <span class="rounded-md bg-rose-50 px-2 py-1 text-rose-700">{{ $num($before) }} resi batasnya sebelum kalender ini</span>
                            @endif
                            @if ($after > 0)
                                <span class="rounded-md bg-ink-100 px-2 py-1 text-ink-600">{{ $num($after) }} resi batasnya setelah kalender ini</span>
                            @endif
                        </div>
                    @endif
                    <div class="grid grid-cols-7 gap-1 text-center text-[11px] font-medium uppercase tracking-wide text-ink-500">
                        @foreach (['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'] as $weekday)
                            <div class="py-1">{{ $weekday }}</div>
                        @endforeach
                    </div>
                    <div class="grid grid-cols-7 gap-1">
                        @for ($cursor = $gridStart->copy(); $cursor->lte($gridEnd); $cursor->addDay())
                            @php
                                $row = $byDay->get($cursor->format('Y-m-d'));
                                $count = $row['total'] ?? 0;
                                $past = $cursor->lt($today);
                                $isToday = $cursor->isSameDay($today);
                                $isActive = $date && $date->isSameDay($cursor);
                                $outside = ! $cursor->isSameMonth($month);
                                $heat = $count > 0 ? (int) ceil($count * 4 / $maxDay) : 0;
                            @endphp
                            <a href="{{ $count === 0 ? '#' : ($isActive ? $pick([]) : $pick(['tanggal' => $cursor->format('Y-m-d')])) }}"
                               @if ($count === 0) aria-disabled="true" tabindex="-1" @endif
                               data-day="{{ $cursor->format('Y-m-d') }}"
                               @if ($count > 0)
                                   data-hint-title="{{ $cursor->translatedFormat('l, d M Y') }}"
                                   data-hint="{{ $num($count) }} resi: {{ $num($row['ready']) }} siap dikemas, {{ $num($row['waiting']) }} menunggu stok, {{ $num($row['done']) }} sudah QC.{{ $row['overdue'] > 0 ? ' '.$num($row['overdue']).' sudah lewat batas.' : '' }}"
                               @endif
                               @class(['relative flex min-h-[64px] flex-col rounded-md border p-1.5 text-left transition sm:min-h-[84px] sm:p-2',
                                   'pointer-events-none' => $count === 0,
                                   'opacity-50' => $outside,
                                   'ring-2 ring-brand-500' => $isActive,
                                   'border-amber-400' => $isToday && ! $isActive,
                                   'border-ink-100' => ! $isToday && ! $isActive,
                                   'bg-white' => $count === 0,
                                   'hover:border-ink-300' => $count > 0,
                                   'bg-rose-50' => $count > 0 && ($past || ($row['overdue'] ?? 0) > 0),
                                   'bg-amber-50' => $count > 0 && $isToday && ! ($row['overdue'] ?? 0),
                                   'bg-brand-50' => $count > 0 && ! $past && ! $isToday && $heat <= 2,
                                   'bg-brand-100' => $count > 0 && ! $past && ! $isToday && $heat > 2])>
                                <span @class(['text-xs', 'font-semibold text-amber-700' => $isToday, 'text-ink-500' => ! $isToday])>
                                    {{ $cursor->day }}@if ($isToday)<span class="hidden sm:inline"> · Hari ini</span>@endif
                                </span>
                                @if ($count > 0)
                                    <span @class(['mt-auto text-lg font-semibold leading-6 tabular-nums sm:text-xl',
                                        'text-rose-700' => $past || $row['overdue'] > 0,
                                        'text-ink-950' => ! ($past || $row['overdue'] > 0)])>{{ $num($count) }}</span>
                                    <span class="hidden truncate text-[10px] text-ink-500 sm:block">
                                        {{ $row['ready'] }} siap{{ $row['done'] ? ' · '.$row['done'].' QC' : '' }}
                                    </span>
                                @endif
                            </a>
                        @endfor
                    </div>
                    <p class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-ink-500">
                        <span class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm bg-rose-100 ring-1 ring-rose-200"></span> Lewat batas</span>
                        <span class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm bg-amber-50 ring-1 ring-amber-400"></span> Hari ini</span>
                        <span class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm bg-brand-100"></span> Makin gelap, makin banyak resi</span>
                    </p>
                </div>
            @else
            <div class="max-h-[420px] overflow-auto">
                <table class="erp-table" data-deadline-days>
                    <thead class="sticky top-0 z-10">
                        <tr>
                            <th>Batas kirim</th>
                            <th class="text-right">Resi</th>
                            <th class="text-right"><span class="inline-flex items-center gap-1">Siap dikemas <x-ui.hint text="Belum QC dan semua barangnya kebagian stok." /></span></th>
                            <th class="text-right"><span class="inline-flex items-center gap-1">Menunggu stok <x-ui.hint text="Belum QC, stok belum cukup atau SKU belum dikenal." /></span></th>
                            <th class="text-right"><span class="inline-flex items-center gap-1">Sudah QC <x-ui.hint text="Sudah lolos QC Kemas, tinggal discan di Serah Terima Paket." /></span></th>
                            <th class="hidden w-40 sm:table-cell"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($days as $row)
                            @php
                                $isActive = $date && $date->isSameDay($row['day']);
                                $past = $row['day']->lt($today);
                                $isToday = $row['day']->isSameDay($today);
                                $width = (int) round($row['total'] * 100 / max(1, $days->max('total')));
                            @endphp
                            <tr @class(['bg-brand-50' => $isActive, 'bg-rose-50/40' => ! $isActive && ($past || $row['overdue'] > 0)])>
                                <td>
                                    <a href="{{ $isActive ? $pick([]) : $pick(['tanggal' => $row['day']->format('Y-m-d')]) }}" class="block hover:underline">
                                        <span class="font-medium text-ink-950">{{ $row['day']->translatedFormat('D, d M Y') }}</span>
                                        <span @class(['ml-1 text-xs', 'text-rose-700 font-medium' => $past || $row['overdue'] > 0, 'text-amber-700 font-medium' => $isToday && ! $row['overdue'], 'text-ink-500' => ! $past && ! $isToday])>{{ $dayLabel($row['day']) }}</span>
                                    </a>
                                    @if ($row['overdue'] > 0 && ! $past)
                                        <p class="text-[11px] text-rose-700">{{ $num($row['overdue']) }} sudah lewat jamnya</p>
                                    @endif
                                </td>
                                <td class="text-right font-semibold tabular-nums text-ink-950">{{ $num($row['total']) }}</td>
                                <td class="text-right tabular-nums">{{ $row['ready'] ? $num($row['ready']) : '—' }}</td>
                                <td class="text-right tabular-nums">{{ $row['waiting'] ? $num($row['waiting']) : '—' }}</td>
                                <td class="text-right tabular-nums">{{ $row['done'] ? $num($row['done']) : '—' }}</td>
                                <td class="hidden sm:table-cell">
                                    <div class="h-1.5 w-full rounded-full bg-ink-100">
                                        <div @class(['h-1.5 rounded-full', 'bg-rose-500' => $past, 'bg-amber-500' => $isToday, 'bg-brand-500' => ! $past && ! $isToday]) style="width: {{ $width }}%"></div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-8 text-center text-sm text-ink-500">Tidak ada resi yang belum dikirim dengan batas kirim.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @endif
        </x-ui.card>

        <x-ui.card padding="p-0" title="Per lama PO" subtitle="Lead time produk saat pesanan dibayar.">
            <div class="overflow-x-auto">
                <table class="erp-table" data-deadline-leadtime>
                    <thead>
                        <tr>
                            <th>Lama PO</th>
                            <th class="text-right">Resi</th>
                            <th class="text-right" title="Lewat batas">Lewat</th>
                            <th class="text-right" title="Hari ini + besok">≤ Besok</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($leadTimes as $row)
                            @php
                                $key = $row['po_days'] === null ? '' : (string) $row['po_days'];
                                $isActive = $poDays !== false && $poDays === $row['po_days'];
                                $late = $row['buckets']['overdue'] ?? 0;
                                $near = ($row['buckets']['today'] ?? 0) + ($row['buckets']['tomorrow'] ?? 0);
                            @endphp
                            <tr @class(['bg-brand-50' => $isActive])>
                                <td>
                                    <a href="{{ $isActive ? $pick([]) : $pick(['lama' => $row['po_days'] === null ? 'x' : $key]) }}" class="hover:underline">
                                        @if ($row['po_days'] === null)
                                            <span class="text-ink-500">Belum diketahui</span>
                                        @elseif ($row['po_days'] === 0)
                                            Siap stok
                                        @else
                                            {{ $row['po_days'] }} hari
                                        @endif
                                    </a>
                                </td>
                                <td class="text-right font-semibold tabular-nums text-ink-950">{{ $num($row['total']) }}</td>
                                <td @class(['text-right tabular-nums', 'font-medium text-rose-700' => $late > 0])>{{ $late ? $num($late) : '—' }}</td>
                                <td @class(['text-right tabular-nums', 'font-medium text-amber-700' => $near > 0])>{{ $near ? $num($near) : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-8 text-center text-sm text-ink-500">Belum ada resi.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>

    {{-- Daftar resi --}}
    <div id="daftar-resi" class="frappe-card scroll-mt-16" data-deadline-orders>
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-ink-100 px-4 py-3">
            <div class="min-w-0">
                <h2 class="text-base font-semibold text-ink-950">
                    Resi {{ $activeLabel ? '· '.$activeLabel : 'paling mendesak' }}
                </h2>
                <p class="mt-0.5 text-xs text-ink-500">
                    {{ $num($orders->total()) }} resi, urut prioritas lalu batas kirim terdekat.
                    @if ($activeLabel)
                        <a href="{{ $pick([]) }}" class="font-medium text-brand-700 hover:underline">Tampilkan semua</a>
                    @endif
                </p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="erp-table">
                <thead>
                    <tr>
                        <th><x-ui.sort key="resi">No. Resi</x-ui.sort></th>
                        <th>Barang</th>
                        <th><x-ui.sort key="lama">Lama PO</x-ui.sort></th>
                        <th><x-ui.sort key="dibayar">Dibayar</x-ui.sort></th>
                        <th><x-ui.sort key="batas">Batas kirim</x-ui.sort></th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($orders as $order)
                        @php
                            $late = $order->ship_by && $order->ship_by->lt($now);
                            $first = $order->items->first();
                        @endphp
                        <tr>
                            <td class="align-top">
                                <a href="{{ route('admin.orders.index', ['search' => $order->tracking_number, 'search_by' => 'tracking_number', 'range' => 'semua']) }}"
                                   class="font-mono text-[13px] font-medium text-ink-950 hover:underline">{{ $order->tracking_number }}</a>
                                @if ($order->is_priority)
                                    <x-ui.badge variant="danger" class="ml-1">Prioritas</x-ui.badge>
                                @endif
                                <p class="text-[11px] text-ink-400">{{ $order->order_number }}{{ $order->store_name ? ' · '.$order->store_name : '' }}</p>
                            </td>
                            <td class="max-w-xs align-top">
                                @if ($first)
                                    <p class="truncate text-sm" title="{{ $order->items->map(fn ($item) => $item->sku.' × '.$item->quantity)->implode(', ') }}">
                                        {{ $first->sku }} × {{ $first->quantity }}
                                        @if ($order->items->count() > 1)
                                            <span class="text-xs text-ink-500">+{{ $order->items->count() - 1 }} SKU</span>
                                        @endif
                                    </p>
                                @endif
                                @if ($order->courier)
                                    <p class="truncate text-[11px] text-ink-400">{{ $order->courier }}</p>
                                @endif
                            </td>
                            <td class="align-top text-sm whitespace-nowrap">
                                {{ $order->po_days === null ? '—' : ($order->po_days === 0 ? 'Siap stok' : $order->po_days.' hari') }}
                            </td>
                            <td class="align-top text-sm whitespace-nowrap text-ink-600">
                                {{ ($order->paid_at ?? $order->order_date)?->translatedFormat('d M H:i') ?? '—' }}
                            </td>
                            <td class="align-top whitespace-nowrap">
                                @if ($order->ship_by)
                                    <p @class(['text-sm font-medium', 'text-rose-700' => $late, 'text-ink-950' => ! $late])>{{ $order->ship_by->translatedFormat('d M Y H:i') }}</p>
                                    <p @class(['text-[11px]', 'text-rose-700' => $late, 'text-ink-500' => ! $late])>{{ Deadlines::relative($order->ship_by, $now) }}</p>
                                @else
                                    <span class="text-sm text-ink-500">Belum ada</span>
                                @endif
                            </td>
                            <td class="align-top">
                                <x-ui.badge :variant="Deadlines::statusVariant($order)">{{ Deadlines::status($order) }}</x-ui.badge>
                                @if ($order->stock_note)
                                    <p class="mt-0.5 max-w-48 truncate text-[11px] text-ink-500" title="{{ $order->stock_note }}">{{ $order->stock_note }}</p>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-10 text-center text-sm text-ink-500">Tidak ada resi di kelompok ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-ui.pagination :paginator="$orders" unit="resi" />
    </div>
</x-app-layout>
