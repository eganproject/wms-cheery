{{--
    Surat jalan kain dari gudang ke Cutting: roll yang diambil pemotong untuk
    satu cutting. Dirender dompdf, jadi CSS-nya sederhana.
--}}
@php
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');
    $hasReturn = $rolls->contains(fn ($row) => $row['returned'] > 0);
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Surat Jalan Kain {{ $cutting->code }}</title>
    @include('admin.fabric-cuttings.pdf.style')
</head>
<body>
    <table class="head">
        <tr>
            <td>
                <h1>Surat Jalan Kain ke Cutting</h1>
                <p class="muted">Nomor <strong style="color:#111">{{ $cutting->code }}</strong> · {{ ($rolls->min('date') ?? $cutting->created_at)->translatedFormat('d F Y') }}</p>
            </td>
            <td style="text-align:right; width: 110px;">
                <img src="{{ $qr }}" style="width: 90px; height: 90px;" alt="">
                <div class="small muted">{{ $cutting->code }}</div>
            </td>
        </tr>
    </table>

    <table class="box">
        <tr>
            <td>
                <span class="muted small">Diserahkan kepada (pemotong)</span><br>
                <strong>{{ $cutting->employee?->name ?? '……………………' }}</strong><br>
                @if ($cutting->employee?->position) {{ $cutting->employee->position }}<br> @endif
                @if ($cutting->employee?->phone) {{ $cutting->employee->phone }} @endif
            </td>
            <td>
                <span class="muted small">Untuk dipotong menjadi</span><br>
                <strong>{{ $cutting->productName() }}</strong><br>
                @if (! $cutting->product_group_id && $cutting->product) <span class="sku">{{ $cutting->product->sku }}</span><br> @endif
                Status: {{ \App\Models\FabricCutting::STATUSES[$cutting->status] ?? $cutting->status }}
            </td>
        </tr>
    </table>

    <table class="list">
        <thead>
            <tr>
                <th style="width: 28px;">No</th>
                <th>Kode Roll</th>
                <th>Kain</th>
                <th>Tgl Ambil</th>
                <th class="right">Diambil</th>
                @if ($hasReturn)
                    <th class="right">Kembali</th>
                    <th class="right">Bersih</th>
                @endif
                <th class="check">Cek</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rolls as $row)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td class="sku">{{ $row['code'] }}</td>
                    <td><span class="sku">{{ $row['fabric']->sku }}</span> {{ $row['fabric']->displayName() }}</td>
                    <td>{{ $row['date']?->format('d/m/Y') }}</td>
                    <td class="right">{{ $qty($row['taken']) }} {{ $row['fabric']->unitShort() }}</td>
                    @if ($hasReturn)
                        <td class="right">{{ $row['returned'] > 0 ? $qty($row['returned']).' '.$row['fabric']->unitShort() : '—' }}</td>
                        <td class="right">{{ $qty($row['taken'] - $row['returned']) }} {{ $row['fabric']->unitShort() }}</td>
                    @endif
                    <td class="check">☐</td>
                </tr>
            @endforeach
            @foreach ($totals as $total)
                <tr class="total">
                    <td colspan="4">{{ $loop->first ? 'Total ' : '' }}{{ $total['fabric']->sku }} · {{ $total['rolls'] }} roll</td>
                    <td class="right">{{ $qty($total['taken']) }} {{ $total['fabric']->unitShort() }}</td>
                    @if ($hasReturn)
                        <td class="right">{{ $qty($total['returned']) }} {{ $total['fabric']->unitShort() }}</td>
                        <td class="right">{{ $qty($total['taken'] - $total['returned']) }} {{ $total['fabric']->unitShort() }}</td>
                    @endif
                    <td></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($cutting->note)
        <p class="small"><strong>Catatan:</strong> {{ $cutting->note }}</p>
    @endif

    <p class="small muted">Hasil potong diserahkan ke gudang atau penjahit dengan menyebut nomor <strong>{{ $cutting->code }}</strong>.</p>

    <table class="sign">
        <tr>
            <td><span>Gudang kain<br>{{ $cutting->user?->name }}</span></td>
            <td><span>Diterima pemotong<br>{{ $cutting->employee?->name }}</span></td>
            <td><span>Mengetahui<br>&nbsp;</span></td>
        </tr>
    </table>
</body>
</html>
