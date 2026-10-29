{{--
    Lonceng navbar: jalan pintas ke dokumen yang menunggu persetujuan.
    Ditukar ulang setiap navigasi AJAX supaya angkanya tidak basi.
--}}
<div data-notifications class="flex">
    @can('approvals.view')
        @php $pending = \App\Support\ApprovalCounter::pending(); @endphp
        <a href="{{ route('admin.approvals.index') }}"
           class="btn btn-ghost btn-icon relative"
           title="{{ $pending > 0 ? $pending.' dokumen menunggu persetujuan' : 'Tidak ada dokumen yang menunggu persetujuan' }}">
            <x-icon name="bell" class="h-[18px] w-[18px]" />
            @if ($pending > 0)
                <span class="absolute right-0.5 top-0.5 inline-flex h-4 min-w-[1rem] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-semibold leading-none text-white ring-2 ring-white">
                    {{ $pending > 99 ? '99+' : $pending }}
                </span>
            @endif
            <span class="sr-only">Persetujuan</span>
        </a>
    @endcan
</div>
