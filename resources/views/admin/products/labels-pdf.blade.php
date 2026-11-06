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
                        <div class="sku">{{ $label['sku'] }}</div>
                        @if ($label['name'] !== '')
                            <div class="name">{{ $label['name'] }}</div>
                        @endif
                        @if ($label['variant'] !== '')
                            <div class="variant">{{ $label['variant'] }}</div>
                        @endif
                    </div>
                </div>
            @endif
        @endforeach
    </div>
@endforeach
</body>
</html>
