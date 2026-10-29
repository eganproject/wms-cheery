<x-app-layout title="Ubah Produk Varian">
    <x-ui.page-header :title="$group->name" subtitle="Perubahan nama, kategori, dan atribut ikut diterapkan ke nama semua varian."
                      :back="route('admin.product-groups.show', $group)" />

    <form method="POST" action="{{ route('admin.product-groups.update', $group) }}" enctype="multipart/form-data"
          x-data="{ option1Name: @js($group->option1_name), option2Name: @js($group->option2_name ?? '') }" class="space-y-6">
        @csrf
        @method('PUT')

        @include('admin.product-groups.partials.fields', ['group' => $group])

        <p class="text-xs text-ink-500" x-show="@js($group->hasSecondOption()) && option2Name.trim() === ''" x-cloak>
            Atribut kedua dikosongkan: nilai atribut kedua di semua varian akan dihapus dari namanya.
        </p>

        <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
            <x-ui.button :href="route('admin.product-groups.show', $group)" variant="secondary">Batal</x-ui.button>
            <x-ui.button type="submit" icon="check">Simpan Perubahan</x-ui.button>
        </div>
    </form>
</x-app-layout>
