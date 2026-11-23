{{-- Rincian stok tercatat untuk barang yang sedang bergerak di jalur kirim.

     Stok baru berkurang saat serah terima, jadi unit yang sudah dikemas di
     QC Kemas masih termasuk stok tercatat walau tidak lagi di rak:
     stok tercatat = di rak + di paket QC. "Belum dikemas" adalah pesanan
     yang sudah masuk tetapi barangnya belum discan — bagian dari stok di rak
     yang sudah ditunggu pembeli. --}}
@php
    $packed = (int) ($packedUnits ?? $product->packed_units ?? 0);
    $unpacked = (int) ($unpackedUnits ?? $product->unpacked_units ?? 0);
    $onRack = (int) $product->stock - $packed;
    $fmt = fn (int $value) => number_format($value, 0, ',', '.');
@endphp
@if (! $product->isBundle() && ($packed > 0 || $unpacked > 0))
    <div @class(["mt-1 flex flex-col gap-0.5 text-[11px]", ($align ?? "end") === "start" ? "items-start" : "items-end"])>
        @if ($packed > 0)
            <span class="inline-flex items-center gap-1 whitespace-nowrap rounded-md bg-amber-50 px-1.5 py-0.5 font-medium text-amber-700"
                  title="Stok {{ $fmt((int) $product->stock) }} = tersedia di rak {{ $fmt(max(0, $onRack)) }} + sudah di-QC {{ $fmt($packed) }} (sudah dikemas, belum diserahkan ke kurir).">
                Sudah di-QC {{ $fmt($packed) }} &middot; tersedia {{ $fmt(max(0, $onRack)) }}
            </span>
        @endif
        @if ($unpacked > 0)
            <span class="text-ink-500" title="Pesanan yang sudah masuk tetapi barangnya belum discan di QC Kemas.">
                Belum dikemas {{ $fmt($unpacked) }}
            </span>
        @endif
    </div>
@endif
