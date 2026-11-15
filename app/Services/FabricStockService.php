<?php

namespace App\Services;

use App\Models\Fabric;
use App\Models\FabricMovement;
use App\Models\FabricReceipt;
use App\Models\FabricRoll;
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
    public function issue(FabricRoll $roll, ?float $quantity, ?string $destination, ?string $note, ?User $user): FabricMovement
    {
        return DB::transaction(function () use ($roll, $quantity, $destination, $note, $user) {
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
                'destination' => $destination,
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
                'destination' => $movement->destination,
                'note' => 'Batal keluar '.$movement->moved_at->format('d/m/Y H:i'),
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
