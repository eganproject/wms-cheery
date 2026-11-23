{{-- Pengingat sebelum menghitung: unit di paket QC Kemas yang belum
     diserahkan sudah tidak di rak, tetapi masih termasuk stok tercatat.
     Menghitung rak saat paket seperti itu masih ada membuat unitnya tercatat
     sebagai selisih, lalu terpotong lagi saat paketnya diserahkan. --}}
@php($packedSummary = \App\Models\Product::packedSummary())
@if ($packedSummary['packages'] > 0)
    <div class="{{ $class ?? 'mb-5' }} flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50/60 p-3.5 text-sm text-amber-900">
        <x-icon name="warning" class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
        <div>
            <p class="font-semibold">
                Ada {{ number_format($packedSummary['packages'], 0, ',', '.') }} paket di QC Kemas yang belum diserahkan
                ({{ number_format($packedSummary['units'], 0, ',', '.') }} unit).
            </p>
            <p class="mt-0.5 text-amber-800">
                Barangnya sudah tidak di rak, tetapi masih termasuk stok tercatat. Bila rak dihitung sekarang,
                unit itu akan tercatat selisih lalu terpotong lagi saat paketnya diserahkan.
                Serahkan paketnya ke kurir dulu, atau tunda opname.
                @can('outbounds.view')
                    <a href="{{ route('admin.outbounds.handover') }}" class="font-semibold underline">Buka Serah Terima Paket</a>
                @endcan
            </p>
        </div>
    </div>
@endif
