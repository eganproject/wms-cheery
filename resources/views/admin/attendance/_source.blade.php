{{-- Tanda sumber tap: mesin fingerprint atau HP. --}}
@if ($punch)
    @if ($punch->source === \App\Models\AttendancePunch::SOURCE_PHONE)
        <span class="inline-flex items-center gap-1 rounded-md bg-sky-50 px-1.5 py-0.5 text-[11px] font-medium text-sky-700"
              data-hint="Absen dari aplikasi HP{{ $punch->distance_meters !== null ? ', '.$punch->distance_meters.' m dari lokasi' : '' }}{{ $punch->outside_radius ? ' (di luar radius)' : '' }}.">
            <x-icon name="phone" class="h-3 w-3" />HP
        </span>
    @else
        <span class="inline-flex items-center gap-1 rounded-md bg-ink-100 px-1.5 py-0.5 text-[11px] font-medium text-ink-600"
              data-hint="Tap jari di mesin fingerprint{{ $punch->relationLoaded('device') && $punch->device ? ' '.$punch->device->name : '' }}.">
            <x-icon name="hash" class="h-3 w-3" />Mesin
        </span>
    @endif
@endif
