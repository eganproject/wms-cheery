{{--
    Surat jalan hasil potong ke penjahit: satu batch WIP Jahit. Nomor batch
    (JHT-) dicocokkan saat barang jadinya diterima lewat Penerimaan Barang.
--}}
@php
    $num = fn ($value) => number_format((int) $value, 0, ',', '.');
    $supplier = $batch->supplier;
    $cutting = $batch->cutting;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Surat Jalan Jahit {{ $batch->code }}</title>
    @include('admin.fabric-cuttings.pdf.style')
</head>
<body>
    <table class="head">
        <tr>
            <td>
                <h1>Surat Jalan ke Penjahit</h1>
                <p class="muted">Nomor <strong style="color:#111">{{ $batch->code }}</strong> · {{ ($batch->sent_at ?? $batch->date)->translatedFormat('d F Y') }}</p>
            </td>
            <td style="text-align:right; width: 110px;">
                <img src="{{ $qr }}" style="width: 90px; height: 90px;" alt="">
                <div class="small muted">{{ $batch->code }}</div>
            </td>
        </tr>
    </table>

    <table class="box">
        <tr>
            <td>
                <span class="muted small">Dikirim kepada (penjahit)</span><br>
                <strong>{{ $supplier->name }}</strong><br>
                @if ($supplier->contact_name) {{ $supplier->contact_name }}<br> @endif
                @if ($supplier->phone) {{ $supplier->phone }}<br> @endif
                @if ($supplier->address) <span class="small">{{ $supplier->address }}</span> @endif
            </td>
            <td>
                <span class="muted small">Hasil potong</span><br>
                @if ($cutting)
                    Cutting: <strong>{{ $cutting->code }}</strong><br>
                    Pemotong: {{ $cutting->employee?->name ?? '—' }}<br>
                @else
                    Input manual (tanpa cutting)<br>
                @endif
                Tanggal potong: {{ $batch->date->translatedFormat('d F Y') }}<br>
                Tanggal kirim: {{ ($batch->sent_at ?? $batch->date)->translatedFormat('d F Y') }}
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
                <th class="check">Cek</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($batch->items as $item)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td class="sku">{{ $item->product->sku }}</td>
                    <td>{{ $item->product->name }}</td>
                    <td class="right">{{ $num($item->quantity) }}</td>
                    <td>{{ $item->product->unit ?: 'pcs' }}</td>
                    <td class="check">☐</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="3">Total</td>
                <td class="right">{{ $num($batch->items->sum('quantity')) }}</td>
                <td colspan="2">pcs potongan</td>
            </tr>
        </tbody>
    </table>

    @if ($batch->note)
        <p class="small"><strong>Catatan:</strong> {{ $batch->note }}</p>
    @endif

    <p class="small muted">Mohon cantumkan nomor <strong>{{ $batch->code }}</strong> pada surat jalan saat barang jadi dikirim kembali.</p>

    <table class="sign">
        <tr>
            <td><span>Dibuat oleh<br>{{ $batch->user?->name }}</span></td>
            <td><span>Pengirim<br>&nbsp;</span></td>
            <td><span>Diterima penjahit<br>{{ $supplier->name }}</span></td>
        </tr>
    </table>
</body>
</html>
