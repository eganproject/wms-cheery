{{-- Unit yang sudah dikemas di QC Kemas tetapi belum diserahkan ke kurir.
     Masih termasuk saldo stok (stok baru berkurang saat serah terima), jadi
     disebut terpisah supaya jelas berapa yang sebenarnya masih di rak. --}}
@php($packed = (int) ($packedUnits ?? $product->packed_units ?? 0))
@if ($packed > 0 && ! $product->isBundle())
    <p class="mt-1 inline-flex items-center gap-1 rounded-md bg-amber-50 px-1.5 py-0.5 text-[11px] font-medium text-amber-700"
       title="Sudah dikemas di QC Kemas, belum diserahkan ke kurir. Di rak tinggal {{ number_format(max(0, (int) $product->stock - $packed), 0, ',', '.') }} {{ $product->unit }}.">
        {{ number_format($packed, 0, ',', '.') }} di paket QC &middot; di rak {{ number_format(max(0, (int) $product->stock - $packed), 0, ',', '.') }}
    </p>
@endif
