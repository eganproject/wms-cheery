@php
    $messages = array_filter([
        'success' => session('success'),
        'error' => session('error'),
        'status' => session('status'),
    ]);

    // Toast ERPNext: kotak putih kecil di pojok kanan bawah dengan ikon berwarna.
    $styles = [
        'success' => ['icon' => 'check-circle', 'text' => 'text-green-600'],
        'error' => ['icon' => 'x-circle', 'text' => 'text-red-600'],
        'status' => ['icon' => 'info', 'text' => 'text-blue-600'],
    ];
@endphp

@if (! empty($messages))
    <div class="pointer-events-none fixed inset-x-0 bottom-20 z-[60] flex flex-col items-center gap-2 px-4 sm:inset-x-auto sm:bottom-5 sm:right-5 sm:items-end lg:bottom-5">
        @foreach ($messages as $type => $message)
            <div x-data="{ show: true }"
                 x-show="show"
                 x-init="setTimeout(() => show = false, {{ $type === 'error' ? 8000 : 5000 }})"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 translate-y-2"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-end="opacity-0 translate-y-2"
                 role="{{ $type === 'error' ? 'alert' : 'status' }}"
                 class="pointer-events-auto flex w-full max-w-sm items-start gap-2.5 rounded-xl border border-ink-100 bg-white px-3.5 py-3 shadow-lift">
                <x-icon :name="$styles[$type]['icon']" class="mt-0.5 h-4 w-4 shrink-0 {{ $styles[$type]['text'] }}" />
                <p class="flex-1 text-sm text-ink-800">{{ $message }}</p>
                <button type="button" @click="show = false" class="-mr-1 rounded text-ink-400 transition hover:text-ink-700">
                    <x-icon name="close" class="h-4 w-4" />
                    <span class="sr-only">Tutup</span>
                </button>
            </div>
        @endforeach
    </div>
@endif
