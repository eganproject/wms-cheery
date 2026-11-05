{{--
    Rencana produksi PO versi PDF untuk brand owner. Dirender dompdf: CSS
    sederhana saja. Hanya barang dan jumlah, tanpa data pembeli.
--}}
@php
    $num = fn ($value) => number_format((int) $value, 0, ',', '.');
    $dates = $plan->dates;
    $keys = $dates->map(fn ($date) => $date->format('Y-m-d'));
    $showDays = $dates->count() <= 14;
    $totals = $plan->totals;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Rencana Produksi PO</title>
    <style>
        @page { margin: 14mm 10mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; color: #111; }
        h1 { font-size: 15pt; margin: 0 0 2px; }
        .muted { color: #666; }
        .small { font-size: 7.5pt; }
        .summary { width: 100%; border-collapse: collapse; margin: 8px 0 10px; }
        .summary td { border: 1px solid #ccc; padding: 5px 7px; width: 25%; }
        .summary .value { font-size: 13pt; font-weight: bold; }
        table.list { width: 100%; border-collapse: collapse; }
        table.list th { background: #111; color: #fff; font-size: 7.5pt; text-align: left; padding: 4px; }
        table.list td { border-bottom: 1px solid #ddd; padding: 4px; }
        table.list tr { page-break-inside: avoid; }
        table.list tr.total td { border-top: 2px solid #111; font-weight: bold; }
        .right, table.list th.right { text-align: right; }
        .sku { font-family: "DejaVu Sans Mono", monospace; font-weight: bold; }
        .short { color: #b42318; font-weight: bold; font-size: 10pt; }
        footer { position: fixed; bottom: -9mm; left: 0; right: 0; font-size: 7pt; color: #777; }
        footer .page:after { content: "Halaman " counter(page); }
    </style>
</head>
<body>
    <footer>
        <span>Rencana Produksi PO · dicetak {{ now()->translatedFormat('d F Y H:i') }}</span>
        <span class="page" style="float: right;"></span>
    </footer>

    <h1>Rencana Produksi PO</h1>
    <p class="muted small">{{ $description }}<br>
        Stok dipakai lebih dulu oleh resi yang batas kirimnya lebih awal, termasuk tanggal yang tidak dipilih.</p>

    <table class="summary">
        <tr>
            <td><span class="muted small">Resi</span><br><span class="value">{{ $num($totals['orders']) }}</span></td>
            <td><span class="muted small">Total dibutuhkan</span><br><span class="value">{{ $num($totals['needed']) }}</span> <span class="small muted">unit · {{ $num($totals['products']) }} barang</span></td>
            <td><span class="muted small">Tertutup stok</span><br><span class="value">{{ $num($totals['covered']) }}</span></td>
            <td><span class="muted small">Harus diproduksi</span><br><span class="value" style="color:#b42318">{{ $num($totals['short']) }}</span></td>
        </tr>
    </table>

    <table class="list">
        <thead>
            <tr>
                <th>No</th>
                <th>Barang</th>
                @if ($showDays)
                    @foreach ($dates as $date)
                        <th class="right">{{ $date->translatedFormat('d M') }}</th>
                    @endforeach
                @endif
                <th class="right">Total</th>
                <th class="right">Stok</th>
                <th class="right">Tgl lain</th>
                <th class="right">Harus diproduksi</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($plan->rows as $row)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td><span class="sku">{{ $row['sku'] }}</span>@if ($row['name'] && $row['name'] !== $row['sku'])<br><span class="muted small">{{ $row['name'] }}</span>@endif</td>
                    @if ($showDays)
                        @foreach ($keys as $key)
                            <td class="right">{{ ($row['days'][$key] ?? 0) ? $num($row['days'][$key]) : '–' }}</td>
                        @endforeach
                    @endif
                    <td class="right"><strong>{{ $num($row['needed']) }}</strong></td>
                    <td class="right">{{ $row['stock'] === null ? '–' : $num($row['available']) }}</td>
                    <td class="right">{{ $row['earlier'] ? $num($row['earlier']) : '–' }}</td>
                    <td class="right {{ $row['short'] > 0 ? 'short' : 'muted' }}">{{ $num($row['short']) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td></td>
                <td>Total</td>
                @if ($showDays)
                    @foreach ($keys as $key)
                        <td class="right">{{ $num($plan->rows->sum(fn ($row) => $row['days'][$key] ?? 0)) }}</td>
                    @endforeach
                @endif
                <td class="right">{{ $num($totals['needed']) }}</td>
                <td></td>
                <td class="right">{{ $num($plan->rows->sum('earlier')) }}</td>
                <td class="right short">{{ $num($totals['short']) }}</td>
            </tr>
        </tbody>
    </table>
    @unless ($showDays)
        <p class="muted small">Rincian per tanggal ada di file Excel.</p>
    @endunless
</body>
</html>
