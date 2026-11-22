<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Fabric;
use App\Models\FabricOpname;
use App\Models\FabricOpnameLine;
use App\Models\FabricRoll;
use App\Services\FabricStockService;
use App\Support\Listing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Stock opname kain: buat daftar roll (semua, per kain, dan/atau per lokasi),
 * isi hasil hitung fisik (ketik atau scan), lalu terapkan sekaligus sebagai
 * koreksi roll. Roll yang tidak dihitung dibiarkan atau dianggap habis.
 */
class FabricOpnameController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:fabrics.view', only: ['index', 'show']),
            new Middleware('can:fabrics.issue', only: ['create', 'store', 'count']),
            new Middleware('can:fabrics.adjust', only: ['apply', 'destroy']),
        ];
    }

    public function index(Request $request): View
    {
        $opnames = FabricOpname::query()
            ->with(['fabric:id,sku,name,color', 'user:id,name'])
            ->withCount([
                'lines',
                'lines as counted_count' => fn (Builder $query) => $query->whereNotNull('counted_quantity'),
            ])
            ->when($request->input('status'), fn (Builder $query, $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate(Listing::perPage($request))
            ->withQueryString();

        return view('admin.fabric-opnames.index', ['opnames' => $opnames]);
    }

    public function create(): View
    {
        return view('admin.fabric-opnames.create', [
            'fabrics' => Fabric::query()->orderBy('sku')->get(['id', 'sku', 'name', 'color']),
            'locations' => FabricRoll::query()->whereNotNull('location')->where('location', '!=', '')->distinct()->orderBy('location')->pluck('location'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'fabric_id' => ['nullable', 'integer', 'exists:fabrics,id'],
            'location' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $rolls = FabricRoll::query()
            ->where('quantity', '>', 0)
            ->when($data['fabric_id'] ?? null, fn (Builder $query, $id) => $query->where('fabric_id', $id))
            ->when(filled($data['location'] ?? null), fn (Builder $query) => $query->where('location', trim($data['location'])))
            ->orderBy('fabric_id')
            ->orderBy('number')
            ->get(['id', 'quantity']);

        if ($rolls->isEmpty()) {
            throw ValidationException::withMessages(['fabric_id' => 'Tidak ada roll berisi untuk pilihan ini.']);
        }

        $opname = DB::transaction(function () use ($data, $rolls, $request) {
            $opname = FabricOpname::create([
                'code' => FabricOpname::nextCode(),
                'date' => $data['date'],
                'fabric_id' => $data['fabric_id'] ?? null,
                'location' => filled($data['location'] ?? null) ? trim($data['location']) : null,
                'status' => FabricOpname::STATUS_DRAFT,
                'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                'user_id' => $request->user()->id,
            ]);

            foreach ($rolls->chunk(500) as $chunk) {
                FabricOpnameLine::insert($chunk->map(fn (FabricRoll $roll) => [
                    'fabric_opname_id' => $opname->id,
                    'fabric_roll_id' => $roll->id,
                    'system_quantity' => $roll->quantity,
                ])->values()->all());
            }

            return $opname;
        });

        return redirect()->route('admin.fabric-opnames.show', $opname)
            ->with('success', "Opname {$opname->code} dibuat dengan {$rolls->count()} roll. Isi hasil hitung tiap roll.");
    }

    public function show(FabricOpname $fabricOpname): View
    {
        $opname = $fabricOpname->load([
            'fabric', 'user:id,name', 'appliedBy:id,name',
            'lines' => fn ($query) => $query->with('roll.fabric')->orderBy('id'),
        ]);

        return view('admin.fabric-opnames.show', [
            'opname' => $opname,
            'rows' => $opname->lines->map(fn (FabricOpnameLine $line) => [
                'id' => $line->id,
                'code' => $line->roll->code,
                'fabric' => $line->roll->fabric->sku.' — '.$line->roll->fabric->displayName(),
                'location' => $line->roll->location,
                'unit' => $line->roll->fabric->unitShort(),
                'system' => $line->system_quantity,
                'counted' => $line->counted_quantity,
                'applied' => $line->applied_difference,
            ])->values(),
        ]);
    }

    /** Simpan hasil hitung satu roll (dari ketik atau scan). Kosong = belum dihitung. */
    public function count(Request $request, FabricOpname $fabricOpname): JsonResponse
    {
        if (! $fabricOpname->isDraft()) {
            return response()->json(['message' => 'Opname ini sudah diterapkan.'], 422);
        }

        $data = $request->validate([
            'line_id' => ['nullable', 'integer'],
            'code' => ['nullable', 'string', 'max:60'],
            'counted' => ['nullable', 'numeric', 'min:0', 'max:999999'],
        ]);

        $line = $fabricOpname->lines()
            ->when($data['line_id'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->when(! ($data['line_id'] ?? null), fn ($query) => $query->whereHas('roll', fn ($query) => $query->where('code', strtoupper(trim((string) ($data['code'] ?? ''))))))
            ->first();

        if (! $line) {
            return response()->json(['message' => 'Roll ini tidak ada di daftar opname ini.'], 422);
        }

        $counted = $data['counted'] ?? null;
        $line->forceFill([
            'counted_quantity' => $counted === null || $counted === '' ? null : round((float) $counted, 2),
            'counted_at' => $counted === null || $counted === '' ? null : now(),
            'counted_by' => $request->user()->id,
        ])->save();

        return response()->json(['line_id' => $line->id, 'counted' => $line->counted_quantity]);
    }

    public function apply(Request $request, FabricOpname $fabricOpname, FabricStockService $stock): RedirectResponse
    {
        if (! $fabricOpname->isDraft()) {
            return back()->with('error', "Opname {$fabricOpname->code} sudah diterapkan.");
        }

        $data = $request->validate([
            'missing_rule' => ['required', 'in:'.implode(',', array_keys(FabricOpname::MISSING_RULES))],
        ], ['missing_rule.required' => 'Pilih perlakuan roll yang tidak dihitung.']);

        $changed = 0;

        DB::transaction(function () use ($fabricOpname, $data, $stock, $request, &$changed) {
            $lines = $fabricOpname->lines()->with('roll')->lockForUpdate()->get();
            $note = 'Opname '.$fabricOpname->code;

            foreach ($lines as $line) {
                $target = $line->counted_quantity ?? ($data['missing_rule'] === 'empty' ? 0.0 : null);

                if ($target === null) {
                    continue;
                }

                $movement = $stock->adjust($line->roll, (float) $target, $line->counted_quantity === null ? $note.' (tidak ditemukan)' : $note, $request->user());
                $line->forceFill(['applied_difference' => $movement ? $movement->quantity : 0])->save();
                $changed += $movement ? 1 : 0;
            }

            $fabricOpname->forceFill([
                'status' => FabricOpname::STATUS_APPLIED,
                'missing_rule' => $data['missing_rule'],
                'applied_at' => now(),
                'applied_by' => $request->user()->id,
            ])->save();
        });

        return redirect()->route('admin.fabric-opnames.show', $fabricOpname)
            ->with('success', "Opname {$fabricOpname->code} diterapkan: {$changed} roll dikoreksi.");
    }

    public function destroy(FabricOpname $fabricOpname): RedirectResponse
    {
        if (! $fabricOpname->isDraft()) {
            return back()->with('error', 'Opname yang sudah diterapkan tidak bisa dihapus.');
        }

        $fabricOpname->delete();

        return redirect()->route('admin.fabric-opnames.index')->with('success', "Opname {$fabricOpname->code} dihapus.");
    }
}
