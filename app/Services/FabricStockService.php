<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Fabric;
use App\Models\FabricCutting;
use App\Models\FabricMovement;
use App\Models\FabricReceipt;
use App\Models\FabricRoll;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Semua perubahan sisa roll kain lewat sini, supaya setiap perubahan
 * meninggalkan satu baris mutasi lengkap dengan sisa sesudahnya.
 *
 * Kain hanya bisa keluar lewat scan QR roll (issue). Koreksi dipakai saat
 * cek fisik roll menemukan sisa yang berbeda.
 */
class FabricStockService
{
    /**
     * Catat kain masuk. Setiap baris = satu jenis kain dengan panjang per
     * roll dan jumlah roll; tiap roll mendapat kode QR sendiri.
     *
     * @param  array{date: string, supplier_id: ?int, reference: ?string, note: ?string}  $header
     * @param  array<int, array{fabric_id: int, quantity: float|string, rolls: int, lot?: ?string, location?: ?string}>  $lines
     */
    public function receive(array $header, array $lines, ?User $user): FabricReceipt
    {
        return DB::transaction(function () use ($header, $lines, $user) {
            $receipt = FabricReceipt::create([
                'code' => FabricReceipt::nextCode(),
                'date' => $header['date'],
                'supplier_id' => $header['supplier_id'] ?? null,
                'reference' => $header['reference'] ?? null,
                'note' => $header['note'] ?? null,
                'user_id' => $user?->id,
            ]);

            foreach ($lines as $line) {
                $fabric = Fabric::query()->lockForUpdate()->findOrFail($line['fabric_id']);
                $quantity = round((float) $line['quantity'], 2);

                for ($i = 0; $i < (int) $line['rolls']; $i++) {
                    $number = $fabric->nextRollNumber();

                    $roll = FabricRoll::create([
                        'fabric_id' => $fabric->id,
                        'fabric_receipt_id' => $receipt->id,
                        'code' => $fabric->rollCode($number),
                        'number' => $number,
                        'initial_quantity' => $quantity,
                        // Bertambah lewat mutasi masuk di bawah.
                        'quantity' => 0,
                        'lot' => $line['lot'] ?? null,
                        'location' => $line['location'] ?? null,
                    ]);

                    $this->record($roll, FabricMovement::TYPE_IN, $quantity, $user, [
                        'fabric_receipt_id' => $receipt->id,
                        'moved_at' => Carbon::parse($header['date'])->setTimeFrom(now()),
                    ]);
                }
            }

            return $receipt;
        });
    }

    /**
     * Kain keluar dari satu roll (hasil scan). $quantity null = ambil semua
     * sisanya.
     */
    public function issue(FabricRoll $roll, ?float $quantity, ?Product $product, ?string $note, ?User $user, ?Employee $cutter = null): FabricMovement
    {
        return DB::transaction(function () use ($roll, $quantity, $product, $note, $user, $cutter) {
            $roll = FabricRoll::query()->with('fabric')->lockForUpdate()->findOrFail($roll->id);

            if ($roll->isEmpty()) {
                throw ValidationException::withMessages([
                    'quantity' => "Roll {$roll->code} sudah habis.",
                ]);
            }

            $take = $quantity === null ? $roll->quantity : round($quantity, 2);

            if ($take <= 0) {
                throw ValidationException::withMessages(['quantity' => 'Jumlah kain keluar harus lebih dari 0.']);
            }

            if ($take > $roll->quantity + 0.001) {
                throw ValidationException::withMessages([
                    'quantity' => "Sisa roll {$roll->code} hanya {$roll->fabric->format($roll->quantity)}; tidak bisa keluar {$roll->fabric->format($take)}.",
                ]);
            }

            return $this->record($roll, FabricMovement::TYPE_OUT, -$take, $user, [
                'product_id' => $product?->id,
                'fabric_cutting_id' => $product ? $this->openCutting($product, $cutter, $user)->id : null,
                'note' => $note,
            ]);
        });
    }

    /** Sisa roll menurut cek fisik. Selisihnya dicatat sebagai koreksi. */
    public function adjust(FabricRoll $roll, float $actual, ?string $note, ?User $user): ?FabricMovement
    {
        return DB::transaction(function () use ($roll, $actual, $note, $user) {
            $roll = FabricRoll::query()->lockForUpdate()->findOrFail($roll->id);
            $difference = round($actual - $roll->quantity, 2);

            if (abs($difference) < 0.005) {
                return null;
            }

            return $this->record($roll, FabricMovement::TYPE_ADJUST, $difference, $user, ['note' => $note]);
        });
    }

    /** Batalkan kain keluar: jumlahnya kembali ke roll asalnya. */
    public function cancel(FabricMovement $movement, ?User $user): FabricMovement
    {
        return DB::transaction(function () use ($movement, $user) {
            $movement = FabricMovement::query()->lockForUpdate()->findOrFail($movement->id);

            if ($movement->type !== FabricMovement::TYPE_OUT || $movement->isCancelled()) {
                throw ValidationException::withMessages(['movement' => 'Kain keluar ini sudah dibatalkan atau bukan kain keluar.']);
            }

            $roll = FabricRoll::query()->lockForUpdate()->findOrFail($movement->fabric_roll_id);
            $movement->forceFill(['cancelled_at' => now()])->save();

            return $this->record($roll, FabricMovement::TYPE_CANCEL, -$movement->quantity, $user, [
                'reverses_id' => $movement->id,
                'product_id' => $movement->product_id,
                'fabric_cutting_id' => $movement->fabric_cutting_id,
                'destination' => $movement->destination,
                'note' => 'Batal keluar '.$movement->moved_at->format('d/m/Y H:i'),
            ]);
        });
    }

    /**
     * Kain keluar yang belum diproses boleh dihapus (salah scan): belum
     * dibatalkan, belum ada gerakan lain sesudahnya di roll itu, dan
     * cuttingnya masih berjalan tanpa batch penjahit. Jumlahnya kembali ke
     * roll; cutting yang jadi kosong ikut dihapus.
     */
    public function deletable(FabricMovement $movement): bool
    {
        return $this->deletableIds(collect([$movement]))->isNotEmpty();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, FabricMovement>  $movements
     * @return \Illuminate\Support\Collection<int, int>
     */
    public function deletableIds($movements)
    {
        $candidates = $movements->filter(fn (FabricMovement $movement) => $movement->type === FabricMovement::TYPE_OUT && ! $movement->cancelled_at);
        if ($candidates->isEmpty()) {
            return collect();
        }

        $latest = FabricMovement::query()
            ->whereIn('fabric_roll_id', $candidates->pluck('fabric_roll_id')->unique())
            ->selectRaw('fabric_roll_id, MAX(id) AS last_id')
            ->groupBy('fabric_roll_id')
            ->pluck('last_id', 'fabric_roll_id');

        $cuttingIds = $candidates->pluck('fabric_cutting_id')->filter()->unique();
        $busy = FabricCutting::query()
            ->whereIn('id', $cuttingIds)
            ->where(fn ($query) => $query->where('status', '!=', FabricCutting::STATUS_CUTTING)->orWhereHas('batches'))
            ->pluck('id')
            ->flip();

        return $candidates
            ->filter(fn (FabricMovement $movement) => (int) ($latest[$movement->fabric_roll_id] ?? 0) === $movement->id
                && ! ($movement->fabric_cutting_id && $busy->has($movement->fabric_cutting_id)))
            ->pluck('id')
            ->values();
    }

    public function deleteIssue(FabricMovement $movement): void
    {
        DB::transaction(function () use ($movement) {
            $movement = FabricMovement::query()->lockForUpdate()->findOrFail($movement->id);
            $roll = FabricRoll::query()->lockForUpdate()->findOrFail($movement->fabric_roll_id);

            if (! $this->deletable($movement)) {
                throw ValidationException::withMessages(['movement' => 'Kain keluar ini sudah diproses (ada gerakan roll sesudahnya atau hasil potong sudah diserahkan ke penjahit). Gunakan Batalkan.']);
            }

            $balance = round($roll->quantity - $movement->quantity, 2);
            $roll->forceFill(['quantity' => $balance, 'emptied_at' => $balance <= 0 ? $roll->emptied_at : null])->save();
            $cuttingId = $movement->fabric_cutting_id;
            $movement->delete();

            if ($cuttingId && ! FabricMovement::query()->where('fabric_cutting_id', $cuttingId)->exists()) {
                FabricCutting::query()->whereKey($cuttingId)->delete();
            }
        });
    }

    /**
     * Cutting terbuka untuk barang (induk) + pemotong yang sama; dibuat bila
     * belum ada. Beberapa roll untuk barang yang sama masuk satu cutting.
     */
    public function openCutting(Product $product, ?Employee $cutter, ?User $user): FabricCutting
    {
        $cutting = FabricCutting::query()
            ->open()
            ->when(
                $product->product_group_id,
                fn ($query, $groupId) => $query->where('product_group_id', $groupId),
                fn ($query) => $query->whereNull('product_group_id')->where('product_id', $product->id),
            )
            ->where('employee_id', $cutter?->id)
            ->lockForUpdate()
            ->oldest('id')
            ->first();

        return $cutting ?? FabricCutting::create([
            'code' => FabricCutting::nextCode(),
            'product_id' => $product->id,
            'product_group_id' => $product->product_group_id,
            'employee_id' => $cutter?->id,
            'status' => FabricCutting::STATUS_CUTTING,
            'user_id' => $user?->id,
        ]);
    }

    /** Sisa kain dari cutting dikembalikan ke roll asalnya. */
    public function returnToRoll(FabricRoll $roll, float $quantity, FabricCutting $cutting, ?User $user, ?string $note = null): FabricMovement
    {
        return DB::transaction(function () use ($roll, $quantity, $cutting, $user, $note) {
            $roll = FabricRoll::query()->lockForUpdate()->findOrFail($roll->id);

            return $this->record($roll, FabricMovement::TYPE_RETURN, round($quantity, 2), $user, [
                'fabric_cutting_id' => $cutting->id,
                'product_id' => $cutting->product_id,
                'note' => $note ?? 'Sisa cutting '.$cutting->code,
            ]);
        });
    }

    /** @param  array<string, mixed>  $extra */
    protected function record(FabricRoll $roll, string $type, float $quantity, ?User $user, array $extra = []): FabricMovement
    {
        $balance = round($roll->quantity + $quantity, 2);

        $roll->forceFill([
            'quantity' => $balance,
            'emptied_at' => $balance <= 0 ? ($roll->emptied_at ?? now()) : null,
        ])->save();

        return FabricMovement::create($extra + [
            'fabric_id' => $roll->fabric_id,
            'fabric_roll_id' => $roll->id,
            'type' => $type,
            'quantity' => round($quantity, 2),
            'balance_after' => $balance,
            'user_id' => $user?->id,
            'moved_at' => now(),
        ]);
    }
}
