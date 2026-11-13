{{--
    Surat jalan retur ke pemasok. Dikirim bersama barangnya: bukti bagi
    pemasok, dan nomor RSK yang dicocokkan saat barang penggantinya datang.
    Dirender dompdf, jadi CSS-nya sederhana.
--}}
@php
    $num = fn ($value) => number_format((int) $value, 0, ',', '.');
    $supplier = $disposal->supplier;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Surat Jalan Retur {{ $disposal->code }}</title>
    <style>
        @page { margin: 16mm 14mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; color: #111; }
        h1 { font-size: 16pt; margin: 0; }
        .muted { color: #666; }
        .small { font-size: 8pt; }
        .head { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: top; }
        .box { width: 100%; border-collapse: collapse; margin: 12px 0; }
        .box td { border: 1px solid #ccc; padding: 6px 8px; vertical-align: top; width: 50%; }
        table.list { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.list th { background: #111; color: #fff; font-size: 8.5pt; text-align: left; padding: 5px; }
        table.list td { border-bottom: 1px solid #ddd; padding: 5px; }
        table.list tr.total td { border-top: 2px solid #111; font-weight: bold; }
        .right { text-align: right; }
        .sku { font-family: "DejaVu Sans Mono", monospace; font-weight: bold; }
        .sign { width: 100%; margin-top: 36px; border-collapse: collapse; }
        .sign td { width: 33%; text-align: center; vertical-align: bottom; padding-top: 60px; }
        .sign span { display: inline-block; border-top: 1px solid #111; padding-top: 3px; min-width: 140px; }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td>
                <h1>Surat Jalan Retur Barang</h1>
                <p class="muted">Nomor <strong style="color:#111">{{ $disposal->code }}</strong> · {{ $disposal->date->translatedFormat('d F Y') }}</p>
            </td>
            <td style="text-align:right; width: 110px;">
                <img src="{{ $qr }}" style="width: 90px; height: 90px;" alt="">
                <div class="small muted">{{ $disposal->code }}</div>
            </td>
        </tr>
    </table>

    <table class="box">
        <tr>
            <td>
                <span class="muted small">Dikirim kepada</span><br>
                <strong>{{ $supplier?->name ?? '—' }}</strong><br>
                @if ($supplier?->contact_name) {{ $supplier->contact_name }}<br> @endif
                @if ($supplier?->phone) {{ $supplier->phone }}<br> @endif
                @if ($supplier?->address) <span class="small">{{ $supplier->address }}</span> @endif
            </td>
            <td>
                <span class="muted small">Pengiriman</span><br>
                Tanggal kirim: {{ $disposal->shipped_at?->translatedFormat('d F Y') ?? '……………………' }}<br>
                Ekspedisi: {{ $disposal->courier ?: '……………………' }}<br>
                No. resi: {{ $disposal->tracking_number ?: '……………………' }}<br>
                Kondisi barang: {{ $disposal->isFromDamaged() ? 'rusak / cacat' : 'baik' }}
            </td>
        </tr>
    </table>

    <table class="list">
        <thead>
            <tr>
                <th style="width: 28px;">No</th>
                <th>SKU</th>
                <th>Nama Barang</th>
                <th class="right">Jumlah</th>
                <th>Satuan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($disposal->items as $item)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td class="sku">{{ $item->product->sku }}</td>
                    <td>{{ $item->product->name }}</td>
                    <td class="right">{{ $num($item->quantity) }}</td>
                    <td>{{ $item->product->unit }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="3">Total</td>
                <td class="right">{{ $num($disposal->totalQuantity()) }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>

    @if ($disposal->note)
        <p class="small"><strong>Catatan:</strong> {{ $disposal->note }}</p>
    @endif

    <p class="small muted">Mohon cantumkan nomor <strong>{{ $disposal->code }}</strong> pada surat jalan barang pengganti.</p>

    <table class="sign">
        <tr>
            <td><span>Dibuat oleh<br>{{ $disposal->user?->name }}</span></td>
            <td><span>Pengirim</span></td>
            <td><span>Diterima pemasok</span></td>
        </tr>
    </table>
</body>
</html>
