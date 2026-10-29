@props([
    'action',
    'title' => 'Hapus data ini?',
    'description' => 'Tindakan ini tidak dapat dibatalkan.',
    'confirm' => 'Ya, hapus',
])

<div x-data="{ open: false }" class="inline-flex">
    @isset($trigger)
        <span @click="open = true">{{ $trigger }}</span>
    @else
        <button type="button" @click="open = true" title="Hapus"
                class="btn btn-ghost btn-icon text-ink-500 hover:bg-red-100 hover:text-red-600">
            <x-icon name="trash" class="h-3.5 w-3.5" />
            <span class="sr-only">Hapus</span>
        </button>
    @endisset

    {{-- Dialog konfirmasi ERPNext: judul, satu kalimat, tombol di kanan bawah. --}}
    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-[70] flex items-end justify-center p-3 sm:items-start sm:p-4 sm:pt-[15vh]" role="dialog" aria-modal="true">
            <div x-show="open"
                 x-transition:enter="ease-out duration-150" x-transition:enter-start="opacity-0"
                 x-transition:leave="ease-in duration-100" x-transition:leave-end="opacity-0"
                 @click="open = false" class="fixed inset-0 bg-ink-950/30"></div>

            <div x-show="open"
                 x-transition:enter="ease-out duration-150" x-transition:enter-start="opacity-0 translate-y-2"
                 x-transition:leave="ease-in duration-100" x-transition:leave-end="opacity-0 translate-y-2"
                 @keydown.escape.window="open = false"
                 class="relative w-full max-w-md overflow-hidden rounded-xl bg-white shadow-lift">
                <div class="flex items-center justify-between gap-3 px-5 pt-4">
                    <h3 class="text-lg font-semibold text-ink-950">{{ $title }}</h3>
                    <button type="button" @click="open = false" class="btn btn-ghost btn-icon -mr-2 text-ink-500">
                        <x-icon name="close" class="h-4 w-4" />
                        <span class="sr-only">Tutup</span>
                    </button>
                </div>
                <p class="px-5 pb-5 pt-2 text-sm text-ink-600">{{ $description }}</p>

                <div class="flex flex-col-reverse gap-2 border-t border-ink-100 bg-white px-5 py-3 sm:flex-row sm:justify-end">
                    <x-ui.button type="button" variant="secondary" @click="open = false">Batal</x-ui.button>

                    <form method="POST" action="{{ $action }}">
                        @csrf
                        @method('DELETE')
                        <x-ui.button type="submit" variant="danger" class="w-full sm:w-auto">
                            {{ $confirm }}
                        </x-ui.button>
                    </form>
                </div>
            </div>
        </div>
    </template>
</div>
