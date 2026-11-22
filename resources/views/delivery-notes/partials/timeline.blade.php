{{-- Riwayat tracking: dari kain masuk sampai barang jadi diterima. Variabel: $timeline. --}}
<div>
    <p class="mb-3 font-semibold text-ink-950">Riwayat</p>
    <ol class="relative ml-1.5 space-y-4 border-l border-ink-200 pl-5">
        @foreach ($timeline as $event)
            <li class="relative">
                <span @class([
                    'absolute -left-[26px] top-1 h-2.5 w-2.5 rounded-full ring-4 ring-white',
                    'bg-emerald-500' => $event['tone'] === 'success',
                    'bg-sky-500' => $event['tone'] === 'info',
                    'bg-amber-500' => $event['tone'] === 'warning',
                    'bg-ink-300' => $event['tone'] === 'muted',
                ])></span>
                <p class="text-[11px] tabular-nums text-ink-400">{{ $event['at']->format($event['date_only'] ? 'd/m/Y' : 'd/m/Y H:i') }}</p>
                <p class="text-sm font-medium text-ink-950">{{ $event['title'] }}</p>
                @if ($event['detail'])
                    <p class="text-xs text-ink-500">{{ $event['detail'] }}</p>
                @endif
            </li>
        @endforeach
    </ol>
</div>
