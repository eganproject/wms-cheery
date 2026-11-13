{{--
    Lonceng navbar: dokumen yang menunggu persetujuan dan retur ke pemasok
    yang terlambat. Ditukar ulang setiap navigasi AJAX supaya angkanya tidak
    basi.
--}}
@php
    $user = auth()->user();
    $pending = $user->can('approvals.view') ? \App\Support\ApprovalCounter::pending() : 0;
    $lateReturns = $user->can('disposals.view') ? \App\Models\DamagedDisposal::overdueCount() : 0;
    $total = $pending + $lateReturns;
@endphp
<div data-notifications class="flex">
    @if ($user->can('approvals.view') || $user->can('disposals.view'))
        <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
            <button type="button" x-on:click="open = ! open"
                    class="btn btn-ghost btn-icon relative"
                    title="{{ $total > 0 ? $total.' pemberitahuan' : 'Tidak ada pemberitahuan' }}">
                <x-icon name="bell" class="h-[18px] w-[18px]" />
                @if ($total > 0)
                    <span class="absolute right-0.5 top-0.5 inline-flex h-4 min-w-[1rem] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-semibold leading-none text-white ring-2 ring-white">
                        {{ $total > 99 ? '99+' : $total }}
                    </span>
                @endif
                <span class="sr-only">Pemberitahuan</span>
            </button>

            <div x-show="open" x-cloak x-transition.opacity.duration.120ms
                 class="absolute right-0 z-50 mt-1.5 w-72 overflow-hidden rounded-xl border border-ink-100 bg-white text-sm shadow-lift">
                @can('approvals.view')
                    <a href="{{ route('admin.approvals.index') }}" class="flex items-center gap-3 px-4 py-3 hover:bg-ink-50">
                        <x-icon name="clock" class="h-4 w-4 shrink-0 text-ink-500" />
                        <span class="min-w-0 flex-1 text-ink-800">
                            {{ $pending > 0 ? $pending.' dokumen menunggu persetujuan' : 'Tidak ada dokumen yang menunggu persetujuan' }}
                        </span>
                    </a>
                @endcan
                @can('disposals.view')
                    <a href="{{ route('admin.disposals.index', ['tab' => 'pemasok', 'return_status' => 'overdue']) }}"
                       class="flex items-center gap-3 border-t border-ink-100 px-4 py-3 hover:bg-ink-50" data-late-returns>
                        <x-icon name="truck" class="h-4 w-4 shrink-0 {{ $lateReturns > 0 ? 'text-red-500' : 'text-ink-500' }}" />
                        <span class="min-w-0 flex-1 text-ink-800">
                            {{ $lateReturns > 0 ? $lateReturns.' retur ke pemasok terlambat' : 'Tidak ada retur ke pemasok yang terlambat' }}
                        </span>
                    </a>
                @endcan
            </div>
        </div>
    @endif
</div>
