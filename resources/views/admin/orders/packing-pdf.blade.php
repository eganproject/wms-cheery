{{--
    Packing list versi PDF untuk dicetak dan dibawa packer. Dirender dompdf,
    jadi hanya memakai CSS sederhana (tanpa Tailwind, tanpa flex/grid).
--}}
@php
    use App\Http\Controllers\Admin\PackingListController;
    use App\Support\PackingList;

    $num = fn ($value) => number_format((int) $value, 0, ',', '.');
    $totals = $list->totals;
    $bySku = $mode === PackingList::BY_SKU;
    $withImages = $images !== [];
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Packing List</title>
    <style>
        @page { margin: 14mm 10mm 14mm 10mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; color: #111; }
        h1 { font-size: 15pt; margin: 0 0 2px; }
        .muted { color: #666; }
        .small { font-size: 7.5pt; }
        .meta { margin: 0 0 8px; }
        .summary { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .summary td { border: 1px solid #ccc; padding: 5px 7px; width: 25%; }
        .summary .value { font-size: 13pt; font-weight: bold; }
        table.list { width: 100%; border-collapse: collapse; }
        table.list th { background: #111; color: #fff; font-size: 8pt; text-align: left; padding: 5px; }
        table.list td { border-bottom: 1px solid #ddd; padding: 4px 5px; vertical-align: middle; }
        table.list tr { page-break-inside: avoid; }
        table.list tfoot td { border-top: 2px solid #111; border-bottom: none; font-weight: bold; }
        .right { text-align: right; }
        .center { text-align: center; }
        .sku { font-family: "DejaVu Sans Mono", monospace; font-size: 9pt; font-weight: bold; }
        .qty { font-size: 13pt; font-weight: bold; }
        .remaining { color: #b42318; font-weight: bold; }
        .done { color: #999; }
        .box { display: inline-block; width: 13px; height: 13px; border: 1.5px solid #111; }
        .thumb { width: 38px; height: 38px; }
        .noimg { width: 38px; height: 38px; background: #f1f1f1; }
        .tag { display: inline-block; border: 1px solid #999; border-radius: 3px; padding: 0 3px; font-size: 6.5pt; color: #444; }
        .tag.warn { border-color: #b54708; color: #b54708; }
        footer { position: fixed; bottom: -9mm; left: 0; right: 0; font-size: 7pt; color: #777; }
        footer .page:after { content: "Halaman " counter(page); }
        .sign { width: 100%; margin-top: 18px; border-collapse: collapse; }
        .sign td { width: 33%; padding: 4px 8px; font-size: 8pt; }
        .sign .line { border-bottom: 1px solid #111; height: 34px; }
    </style>
</head>
<body>
    <footer>
        <span>Packing List · dicetak {{ now()->translatedFormat('d F Y H:i') }} oleh {{ auth()->user()?->name }}</span>
        <span class="page" style="float: right;"></span>
    </footer>

    <h1>Packing List{{ $type === PackingListController::DAILY ? ' Harian' : ($type === PackingListController::ALL ? ' Keseluruhan' : '') }}</h1>
    <p class="meta muted small">
        @foreach ($filters as $line){{ $line }}<br>@endforeach
        {{ config('app.name') }}
    </p>

    <table class="summary">
        <tr>
            <td><span class="muted small">Resi</span><br><span class="value">{{ $num($totals['orders']) }}</span>
                <span class="muted small">({{ $num($totals['orders_open']) }} belum QC)</span></td>
            <td><span class="muted small">Unit harus dikemas</span><br><span class="value">{{ $num($totals['ordered']) }}</span>
                <span class="muted small">({{ $num($totals['skus']) }} {{ $bySku ? 'SKU' : 'barang' }})</span></td>
            <td><span class="muted small">Sudah QC Kemas</span><br><span class="value">{{ $num($totals['scanned']) }}</span></td>
            <td><span class="muted small">Sisa belum dikemas</span><br><span class="value">{{ $num($totals['remaining']) }}</span></td>
        </tr>
    </table>

    <table class="list">
        <thead>
            <tr>
                <th class="center" style="width: 16px;">✓</th>
                <th class="right" style="width: 20px;">#</th>
                @if ($withImages)
                    <th style="width: 40px;"></th>
                @endif
                <th>{{ $bySku ? 'SKU di resi' : 'Barang di rak' }}</th>
                <th class="right" style="width: 34px;">Resi</th>
                <th class="right" style="width: 46px;">Total Qty</th>
                <th class="right" style="width: 46px;">Sudah QC</th>
                <th class="right" style="width: 40px;">Sisa</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="center"><span class="box"></span></td>
                    <td class="right muted">{{ $loop->iteration }}</td>
                    @if ($withImages)
                        <td>
                            @if ($row['image_path'] && isset($images[$row['image_path']]))
                                <img src="{{ $images[$row['image_path']] }}" class="thumb" alt="">
                            @else
                                <div class="noimg"></div>
                            @endif
                        </td>
                    @endif
                    <td>
                        <span class="sku">{{ $row['sku'] }}</span>
                        @unless ($row['matched'])
                            <span class="tag warn">SKU belum terdaftar</span>
                        @endunless
                        @if ($row['from_bundle'])
                            <span class="tag">{{ $bySku ? 'Paket bundling' : 'Termasuk isi paket' }}</span>
                        @endif
                        @if ($row['name'])
                            <br><span class="small muted">{{ $row['name'] }}</span>
                        @endif
                    </td>
                    <td class="right">{{ $num($row['orders']) }}</td>
                    <td class="right qty">{{ $num($row['ordered']) }}</td>
                    <td class="right">{{ $num($row['scanned']) }}</td>
                    <td class="right {{ $row['remaining'] > 0 ? 'remaining' : 'done' }}">{{ $num($row['remaining']) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $withImages ? 8 : 7 }}" class="center muted" style="padding: 20px;">Tidak ada barang untuk dikemas pada saringan ini.</td>
                </tr>
            @endforelse
        </tbody>
        @if ($rows->isNotEmpty())
            <tfoot>
                <tr>
                    <td colspan="{{ $withImages ? 4 : 3 }}">Total {{ $num($rows->count()) }} {{ $bySku ? 'SKU' : 'barang' }}</td>
                    <td></td>
                    <td class="right">{{ $num($rows->sum('ordered')) }}</td>
                    <td class="right">{{ $num($rows->sum('scanned')) }}</td>
                    <td class="right">{{ $num($rows->sum('remaining')) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>

    @if ($resi->isNotEmpty())
        <h2 style="font-size: 11pt; margin: 16px 0 4px; page-break-before: always;">Daftar resi yang dikerjakan</h2>
        <p class="muted small" style="margin: 0 0 6px;">Urut prioritas lalu batas kirim terdekat. Ambil labelnya dari rak tunggu.</p>
        <table class="list">
            <thead>
                <tr>
                    <th class="center" style="width: 16px;">✓</th>
                    <th class="right" style="width: 20px;">#</th>
                    <th>No. Resi</th>
                    <th style="width: 92px;">Batas kirim</th>
                    <th style="width: 70px;">Tgl pesanan</th>
                    <th>Isi</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($resi as $order)
                    <tr>
                        <td class="center"><span class="box"></span></td>
                        <td class="right muted">{{ $loop->iteration }}</td>
                        <td><span class="sku">{{ $order->tracking_number }}</span>
                            @if ($order->is_priority) <span class="tag warn">Prioritas</span> @endif</td>
                        <td>{{ $order->ship_by?->format('d/m/Y H:i') ?? '-' }}<br><span class="small muted">{{ $order->deadlineState()[0] ?? '' }}</span></td>
                        <td class="small">{{ $order->order_date?->format('d/m/Y') }}</td>
                        <td class="small">{{ $order->items->map(fn ($item) => $item->sku.' x'.$item->quantity)->implode(', ') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="sign">
        <tr>
            <td>Disiapkan oleh<div class="line"></div></td>
            <td>Packer<div class="line"></div></td>
            <td>Diperiksa oleh<div class="line"></div></td>
        </tr>
    </table>
</body>
</html>
