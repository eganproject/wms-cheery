@props(['fabric', 'size' => 'sm', 'group' => 'kain'])

{{-- Thumbnail foto kain; klik untuk pratinjau besar. --}}
<x-ui.product-image :url="$fabric->image_url" :name="$fabric->displayName()" :sku="$fabric->sku"
                    :size="$size" :group="$group" icon="layers" empty="Belum ada foto kain" {{ $attributes }} />
