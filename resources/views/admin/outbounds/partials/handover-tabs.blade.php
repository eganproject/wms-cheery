{{-- Antrean dan riwayat serah terima: satu menu, dua tampilan. --}}
@php $onHistory = request()->routeIs('admin.outbounds.handover.history'); @endphp
<div class="scrollbar-none mb-4 flex gap-1 overflow-x-auto border-b border-ink-200 text-sm" data-handover-tabs>
    <a href="{{ route('admin.outbounds.handover') }}"
       @class(['-mb-px whitespace-nowrap border-b-2 px-3 py-2', 'border-brand-500 font-medium text-ink-950' => ! $onHistory, 'border-transparent text-ink-500 hover:text-ink-700' => $onHistory])>
        Antrean <span class="ml-1 text-xs text-ink-400">{{ number_format($handoverCount ?? 0, 0, ',', '.') }}</span>
    </a>
    <a href="{{ route('admin.outbounds.handover.history') }}"
       @class(['-mb-px whitespace-nowrap border-b-2 px-3 py-2', 'border-brand-500 font-medium text-ink-950' => $onHistory, 'border-transparent text-ink-500 hover:text-ink-700' => ! $onHistory])>
        Riwayat Serah Terima
    </a>
</div>
