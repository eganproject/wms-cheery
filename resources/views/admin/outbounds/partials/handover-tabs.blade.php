{{-- Antrean, riwayat, dan rekap harian serah terima: satu menu, tiga tampilan. --}}
@php
    $tabs = [
        'admin.outbounds.handover' => 'Antrean',
        'admin.outbounds.handover.history' => 'Riwayat Serah Terima',
        'admin.outbounds.handover.daily' => 'Rekap Harian',
    ];
    $current = collect(array_keys($tabs))->first(fn ($route) => request()->routeIs($route)) ?? 'admin.outbounds.handover';
@endphp
<div class="scrollbar-none mb-4 flex gap-1 overflow-x-auto border-b border-ink-200 text-sm" data-handover-tabs>
    @foreach ($tabs as $route => $label)
        <a href="{{ route($route) }}"
           @class(['-mb-px whitespace-nowrap border-b-2 px-3 py-2', 'border-brand-500 font-medium text-ink-950' => $current === $route, 'border-transparent text-ink-500 hover:text-ink-700' => $current !== $route])>
            {{ $label }}
            @if ($route === 'admin.outbounds.handover')
                <span class="ml-1 text-xs text-ink-400">{{ number_format($handoverCount ?? 0, 0, ',', '.') }}</span>
            @endif
        </a>
    @endforeach
</div>
