{{--
    Label QR SKU untuk dicetak. Dirender dompdf.

    Setiap label diletakkan dengan posisi absolut dalam milimeter, bukan
    lewat tabel: posisinya jadi pasti sama dengan lembar stiker, teks panjang
    tidak bisa memanjangkan baris, dan dompdf jauh lebih cepat (ribuan label
    tabel bersarang butuh puluhan detik).
--}}
@php
    $w = $layout['width'];
    $h = $layout['height'];
    $pad = $layout['pad'];
    $qr = $layout['qr'];
    $textLeft = $pad + $qr + 1.5;
    $textWidth = max($w - $textLeft - $pad, 5);
    $sheet = $layout['sheet'];
    $stacked = $layout['stacked'];
    $showName ??= true;
    $pt = 25.4 / 72;

    if ($stacked) {
        // QR di atas, teks rata tengah di bawahnya: baris SKU, lalu satu
        // baris "Nama · Varian".
        // Tinggi baris teks dihitung dari ukuran hurufnya supaya QR mengambil
        // seluruh sisa tinggi label tanpa pernah menimpa teks.
        $skuLine = $layout['sku_font'] * 1.35 * $pt;
        $nameLine = $layout['font'] * 1.45 * $pt;
        $gap = 0.6;
        $textHeight = $skuLine + ($showName ? $nameLine : 0);
        // 1,2 mm cadangan: printer thermal sering bergeser sedikit saat memotong,
        // jadi teks tidak boleh menempel di tepi bawah. Separuhnya di atas QR
        // supaya seluruh isi tetap di tengah label.
        $qr = round(min($h - 2 * $pad - $gap - $textHeight - 1.2, $w - 2 * $pad), 2);
        $qrTop = $pad + 0.4;
        $textTop = $pad + $qr + $gap;
        $textLeft = $pad;
        $textWidth = $w - 2 * $pad;
    }
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Label QR SKU</title>
    <style>
        @page { margin: {{ $layout['margin_y'] }}mm {{ $layout['margin_x'] }}mm; }
        body { margin: 0; padding: 0; font-family: "DejaVu Sans", sans-serif; color: #000; }
        .page { position: relative; width: {{ $w * $layout['cols'] }}mm; height: {{ $h * $layout['rows'] }}mm; overflow: hidden; }
        .break { page-break-after: always; }
        .cell { position: absolute; width: {{ $w }}mm; height: {{ $h }}mm; overflow: hidden; }
        @if ($sheet)
            {{-- Garis potong tipis; lebar label dikurangi tebal garisnya. --}}
            .cell { border: 0.2mm dashed #c8c8c8; width: {{ $w - 0.4 }}mm; height: {{ $h - 0.4 }}mm; }
        @endif
        .qr { position: absolute; left: {{ $pad }}mm; top: {{ ($h - $qr) / 2 }}mm; width: {{ $qr }}mm; height: {{ $qr }}mm; }
        .text { position: absolute; left: {{ $textLeft }}mm; top: {{ $pad }}mm; width: {{ $textWidth }}mm; height: {{ $h - 2 * $pad }}mm; overflow: hidden; }
        .sku { font-family: "DejaVu Sans Mono", monospace; font-weight: bold; font-size: {{ $layout['sku_font'] }}pt; line-height: 1.15; word-wrap: break-word; }
        .name { font-size: {{ $layout['font'] }}pt; line-height: 1.2; margin-top: 0.8mm; word-wrap: break-word; }
        .variant { font-size: {{ $layout['font'] }}pt; line-height: 1.2; font-weight: bold; margin-top: 0.5mm; word-wrap: break-word; }
        @if ($stacked)
            .qr { left: {{ ($w - $qr) / 2 }}mm; top: {{ $qrTop }}mm; }
            .text { top: {{ $textTop }}mm; height: {{ $h - $textTop }}mm; text-align: center; }
            .sku, .name, .variant { white-space: nowrap; overflow: hidden; margin-top: 0; word-wrap: normal; }
            .sku { line-height: 1.35; }
            .name, .variant { line-height: 1.45; }
        @endif
    </style>
</head>
<body>
@foreach ($pages as $labels)
    <div class="page{{ $loop->last ? '' : ' break' }}">
        @foreach ($labels as $i => $label)
            @php
                $left = ($i % $layout['cols']) * $w;
                $top = intdiv($i, $layout['cols']) * $h;
            @endphp
            @if ($label)
                <div class="cell" style="left: {{ $left }}mm; top: {{ $top }}mm;">
                    <img class="qr" src="{{ $label['qr'] }}" alt="">
                    <div class="text">
                        @php
                            // SKU panjang dikecilkan hurufnya supaya tetap satu baris
                            // (huruf mono: lebar tiap karakter ±0,6 × ukuran huruf).
                            $skuFont = $stacked
                                ? min($layout['sku_font'], round($textWidth / max(mb_strlen($label['sku']), 1) / 0.6 / $pt, 1))
                                : null;
                        @endphp
                        <div class="sku" @if ($skuFont) style="font-size: {{ $skuFont }}pt;" @endif>{{ $label['sku'] }}</div>
                        @if ($stacked)
                            @if ($label['name'] !== '')
                                <div class="name">{{ $label['name'] }}@if ($label['variant'] !== '') · <b>{{ $label['variant'] }}</b>@endif</div>
                            @endif
                        @else
                            @if ($label['name'] !== '')
                                <div class="name">{{ $label['name'] }}</div>
                            @endif
                            @if ($label['variant'] !== '')
                                <div class="variant">{{ $label['variant'] }}</div>
                            @endif
                        @endif
                    </div>
                </div>
            @endif
        @endforeach
    </div>
@endforeach
</body>
</html>
