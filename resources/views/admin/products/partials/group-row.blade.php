{{-- Baris induk barang bervarian di daftar Barang. Klik panah untuk melipat variannya. --}}
<tr class="bg-ink-50/70">
    @can('products.update')
        <td class="w-12 pl-6 pr-0">
            <button type="button" title="Lipat / buka varian"
                    x-on:click="closed.includes({{ $group->id }}) ? closed.splice(closed.indexOf({{ $group->id }}), 1) : closed.push({{ $group->id }})"
                    class="inline-flex h-6 w-6 items-center justify-center rounded text-ink-500 hover:bg-ink-100">
                <x-icon name="chevron-down" class="h-4 w-4 transition" x-bind:class="closed.includes({{ $group->id }}) && '-rotate-90'" />
            </button>
        </td>
    @endcan
    <td colspan="4">
        <div class="flex items-center gap-3">
            @cannot('products.update')
                <button type="button" title="Lipat / buka varian"
                        x-on:click="closed.includes({{ $group->id }}) ? closed.splice(closed.indexOf({{ $group->id }}), 1) : closed.push({{ $group->id }})"
                        class="inline-flex h-6 w-6 items-center justify-center rounded text-ink-500 hover:bg-ink-100">
                    <x-icon name="chevron-down" class="h-4 w-4 transition" x-bind:class="closed.includes({{ $group->id }}) && '-rotate-90'" />
                </button>
            @endcannot
            @if ($group->imageUrl())
                <img src="{{ $group->imageUrl() }}" alt="" class="h-10 w-10 shrink-0 rounded-lg object-cover ring-1 ring-inset ring-ink-100">
            @else
                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white text-ink-300 ring-1 ring-inset ring-ink-100">
                    <x-icon name="layers" class="h-4 w-4" />
                </span>
            @endif
            <a href="{{ route('admin.products.groups.show', $group) }}" class="min-w-0 truncate text-sm font-semibold text-ink-950 hover:underline">{{ $group->name }}</a>
            <x-ui.badge variant="purple" icon="layers">{{ $group->variants_count }} varian</x-ui.badge>
            @if ($group->category)
                <span class="text-xs text-ink-500">{{ $group->category }}</span>
            @endif
        </div>
    </td>
    <td colspan="2">
        <div class="flex items-center justify-end gap-1">
            <a href="{{ route('admin.products.groups.show', $group) }}" title="Lihat produk & semua varian"
               class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                <x-icon name="document" class="h-4 w-4" />
            </a>
            @can('products.update')
                <a href="{{ route('admin.products.groups.edit', $group) }}" title="Ubah produk induk"
                   class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                    <x-icon name="pencil" class="h-4 w-4" />
                </a>
            @endcan
        </div>
    </td>
</tr>
