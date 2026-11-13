<?php

namespace App\Models;

use App\Models\Concerns\GeneratesDocumentCode;
use App\Models\Concerns\FiltersByDate;
use App\Models\Concerns\HasApprovalWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pengeluaran barang di luar penjualan.
 *
 * Barang tidak boleh menguap begitu saja dari gudang. Setiap unit yang keluar
 * tanpa melewati penjualan harus punya alasan yang tercatat — dibuang,
 * dikembalikan ke pemasok, diperbaiki, atau dipindahkan ke saldo rusak.
 *
 * Dokumen ini menyebut dari saldo mana unitnya diambil. Awalnya ia hanya
 * mengurusi saldo rusak, sehingga barang layak jual yang harus dikembalikan ke
 * pemasok atau dibuang karena kedaluwarsa tidak punya jalan keluar sama sekali.
 */
class DamagedDisposal extends Model
{
    use FiltersByDate, GeneratesDocumentCode, HasApprovalWorkflow;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const ACTION_DISCARD = 'dibuang';

    public const ACTION_RETURN = 'dikembalikan';

    public const ACTION_REPAIR = 'diperbaiki';

    /** Barang layak jual yang ternyata rusak: pindah saldo, belum keluar gudang. */
    public const ACTION_WRITE_OFF = 'ditandai_rusak';

    /* Tindak lanjut retur ke pemasok, setelah dokumennya diproses. */
    public const RETURN_UNSENT = 'belum_dikirim';

    public const RETURN_SENT = 'dikirim';

    public const RETURN_DONE = 'selesai';

    /** Sudah diproses tetapi tidak jadi dikirim; unitnya kembali ke saldo asal. */
    public const RETURN_CANCELLED = 'batal';

    protected $fillable = [
        'code',
        'date',
        'bucket',
        'action',
        'supplier_id',
        'return_status',
        'shipped_at',
        'courier',
        'tracking_number',
        'closed_at',
        'closed_by',
        'close_note',
        'note',
        'status',
        'posted_at',
        'user_id',
        'submitted_at',
        'submitted_by',
        'approved_at',
        'approved_by',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'posted_at' => 'datetime',
            'shipped_at' => 'date',
            'closed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(DamagedDisposalItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function replacements(): HasMany
    {
        return $this->hasMany(SupplierReplacement::class);
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /* ------------------------------------------- retur ke pemasok -- */

    public function isSupplierReturn(): bool
    {
        return $this->action === self::ACTION_RETURN;
    }

    /** Retur ke pemasok yang sudah diproses dan punya tindak lanjut. */
    public function tracksSupplierReturn(): bool
    {
        return $this->isSupplierReturn() && $this->isPosted() && $this->return_status !== null;
    }

    public function isReturnUnsent(): bool
    {
        return $this->return_status === self::RETURN_UNSENT;
    }

    public function isReturnSent(): bool
    {
        return $this->return_status === self::RETURN_SENT;
    }

    public function isReturnDone(): bool
    {
        return $this->return_status === self::RETURN_DONE;
    }

    public function isReturnCancelled(): bool
    {
        return $this->return_status === self::RETURN_CANCELLED;
    }

    /** Tidak ada lagi yang perlu ditindaklanjuti: selesai atau batal dikirim. */
    public function isReturnClosed(): bool
    {
        return $this->isReturnDone() || $this->isReturnCancelled();
    }

    /** Status yang masih perlu dikejar. */
    public const OPEN_RETURN_STATUSES = [self::RETURN_UNSENT, self::RETURN_SENT];

    /**
     * Retur terlambat: sudah di pemasok lebih lama dari batas hari, atau
     * sudah diproses tetapi tidak juga dikirim selama batas yang sama.
     */
    public function isReturnOverdue(?int $days = null): bool
    {
        $cutoff = now()->startOfDay()->subDays($days ?? AppSetting::supplierReturnAlertDays());

        return match ($this->return_status) {
            self::RETURN_SENT => $this->shipped_at !== null && $this->shipped_at->lte($cutoff),
            self::RETURN_UNSENT => $this->date !== null && $this->date->lte($cutoff),
            default => false,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function returnStatuses(): array
    {
        return [
            self::RETURN_UNSENT => 'Belum dikirim',
            self::RETURN_SENT => 'Dikirim, menunggu pemasok',
            self::RETURN_DONE => 'Selesai',
            self::RETURN_CANCELLED => 'Batal dikirim',
        ];
    }

    public function returnStatusLabel(): string
    {
        return self::returnStatuses()[$this->return_status] ?? '—';
    }

    public function returnStatusVariant(): string
    {
        return match ($this->return_status) {
            self::RETURN_UNSENT => 'warning',
            self::RETURN_SENT => 'info',
            self::RETURN_DONE => 'success',
            self::RETURN_CANCELLED => 'outline',
            default => 'outline',
        };
    }

    /** Hari sejak dikirim ke pemasok, selama belum selesai. */
    public function daysWithSupplier(): ?int
    {
        if (! $this->isReturnSent() || ! $this->shipped_at) {
            return null;
        }

        return (int) $this->shipped_at->startOfDay()->diffInDays(now()->startOfDay());
    }

    public function replacedQuantity(): int
    {
        return (int) $this->items->sum('replaced_quantity');
    }

    public function rejectedQuantity(): int
    {
        return (int) $this->items->sum('rejected_quantity');
    }

    public function outstandingQuantity(): int
    {
        return (int) $this->items->sum(fn (DamagedDisposalItem $item) => $item->outstandingQuantity());
    }

    public function scopeSupplierReturns(Builder $query): Builder
    {
        return $query->where('action', self::ACTION_RETURN)->whereNotNull('return_status');
    }

    public function scopeOpenSupplierReturns(Builder $query): Builder
    {
        return $query->supplierReturns()->whereIn('return_status', self::OPEN_RETURN_STATUSES);
    }

    /** Jumlah retur terlambat, dihitung sekali per permintaan (lonceng + Beranda). */
    public static function overdueCount(): int
    {
        $attributes = request()->attributes;

        if (! $attributes->has('overdue_supplier_returns')) {
            $attributes->set('overdue_supplier_returns', static::overdueSupplierReturns()->count());
        }

        return (int) $attributes->get('overdue_supplier_returns');
    }

    /** Kebalikan isReturnOverdue() dalam bentuk kueri. */
    public function scopeOverdueSupplierReturns(Builder $query, ?int $days = null): Builder
    {
        $cutoff = now()->startOfDay()->subDays($days ?? AppSetting::supplierReturnAlertDays())->toDateString();

        return $query->supplierReturns()->where(function (Builder $query) use ($cutoff) {
            $query->where(fn (Builder $query) => $query->where('return_status', self::RETURN_SENT)->whereDate('shipped_at', '<=', $cutoff))
                ->orWhere(fn (Builder $query) => $query->where('return_status', self::RETURN_UNSENT)->whereDate('date', '<=', $cutoff));
        });
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    /* ------------------------------------------------------------ saldo -- */

    /** Saldo yang unitnya diambil. */
    public function sourceBucket(): string
    {
        return $this->bucket === StockMovement::BUCKET_GOOD
            ? StockMovement::BUCKET_GOOD
            : StockMovement::BUCKET_DAMAGED;
    }

    /**
     * Saldo yang menerima unitnya, bila memang tidak benar-benar keluar gudang.
     *
     * Perbaikan memulangkan barang rusak menjadi layak jual; penandaan rusak
     * melakukan kebalikannya. Selebihnya barang pergi untuk selamanya, dan
     * tidak ada saldo yang menerimanya.
     */
    public function targetBucket(): ?string
    {
        return match ($this->action) {
            self::ACTION_REPAIR => StockMovement::BUCKET_GOOD,
            self::ACTION_WRITE_OFF => StockMovement::BUCKET_DAMAGED,
            default => null,
        };
    }

    public function isFromDamaged(): bool
    {
        return $this->sourceBucket() === StockMovement::BUCKET_DAMAGED;
    }

    public function bucketLabel(): string
    {
        return $this->isFromDamaged() ? 'Stok rusak' : 'Stok layak jual';
    }

    /** Unitnya benar-benar meninggalkan gudang, bukan sekadar berpindah saldo. */
    public function leavesTheWarehouse(): bool
    {
        return $this->targetBucket() === null;
    }

    /**
     * Barang yang diperbaiki kembali menjadi layak jual.
     */
    public function returnsToSellableStock(): bool
    {
        return $this->targetBucket() === StockMovement::BUCKET_GOOD;
    }

    public function totalQuantity(): int
    {
        return (int) $this->items->sum('quantity');
    }

    public function actionLabel(): string
    {
        return self::actions()[$this->action] ?? $this->action;
    }

    /**
     * Seluruh tindakan yang dikenal.
     *
     * @return array<string, string>
     */
    public static function actions(): array
    {
        return [
            self::ACTION_DISCARD => 'Dibuang / dimusnahkan',
            self::ACTION_RETURN => 'Dikembalikan ke pemasok',
            self::ACTION_REPAIR => 'Diperbaiki jadi layak jual',
            self::ACTION_WRITE_OFF => 'Dipindahkan ke stok rusak',
        ];
    }

    /**
     * Tindakan yang masuk akal bagi saldo tertentu.
     *
     * Memperbaiki barang yang memang layak jual tidak punya arti, begitu pula
     * menandai rusak sesuatu yang sudah tercatat rusak.
     *
     * @return array<string, string>
     */
    public static function actionsFor(?string $bucket): array
    {
        $shared = [
            self::ACTION_DISCARD => self::actions()[self::ACTION_DISCARD],
            self::ACTION_RETURN => self::actions()[self::ACTION_RETURN],
        ];

        return match ($bucket) {
            StockMovement::BUCKET_GOOD => $shared + [
                self::ACTION_WRITE_OFF => self::actions()[self::ACTION_WRITE_OFF],
            ],
            StockMovement::BUCKET_DAMAGED => $shared + [
                self::ACTION_REPAIR => self::actions()[self::ACTION_REPAIR],
            ],
            default => [],
        };
    }

    /**
     * Kalimat yang menjelaskan apa yang terjadi pada saldo. Sebelum diproses
     * kalimatnya berbentuk rencana, supaya draft tidak terbaca seolah stoknya
     * sudah bergerak.
     */
    public function postedSummary(): string
    {
        $done = $this->isPosted();

        return match ($this->targetBucket()) {
            StockMovement::BUCKET_GOOD => $done
                ? 'Stok rusak berkurang, stok layak jual bertambah.'
                : 'Saat diproses, stok rusak berkurang dan stok layak jual bertambah.',
            StockMovement::BUCKET_DAMAGED => $done
                ? 'Stok layak jual berkurang, stok rusak bertambah.'
                : 'Saat diproses, stok layak jual berkurang dan stok rusak bertambah.',
            default => match (true) {
                $done && $this->isFromDamaged() => 'Stok rusak sudah berkurang.',
                $done => 'Stok layak jual sudah berkurang.',
                $this->isFromDamaged() => 'Saat diproses, stok rusak berkurang.',
                default => 'Saat diproses, stok layak jual berkurang.',
            },
        };
    }

    /**
     * @return array<int, string>
     */
    public function postingBlockers(): array
    {
        $blockers = [];

        if ($this->items->isEmpty()) {
            $blockers[] = 'Dokumen belum memiliki baris barang.';
        }

        // Penjagaan kedua, seandainya data tersimpan dari jalur lain: tindakan
        // dan saldo asal harus tetap cocok saat dokumen benar-benar diposting.
        if (! array_key_exists($this->action, static::actionsFor($this->sourceBucket()))) {
            $blockers[] = "Tindakan \"{$this->actionLabel()}\" tidak berlaku untuk {$this->bucketLabel()}.";
        }

        return $blockers;
    }

    public function isReadyToPost(): bool
    {
        return $this->postingBlockers() === [];
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $query->when($term, function (Builder $query, string $term) {
            $query->where(function (Builder $query) use ($term) {
                $query->where('code', 'like', "%{$term}%")
                    ->orWhere('note', 'like', "%{$term}%")
                    ->orWhereHas('supplier', fn (Builder $query) => $query->where('name', 'like', "%{$term}%"));
            });
        });
    }

    /**
     * Nomor dokumen berikutnya, contoh: RSK-202608-0007.
     */
    protected static function codePrefix(): string
    {
        return 'RSK-'.now()->format('Ym').'-';
    }

    /**
     * Retur ke pemasok yang stoknya sudah keluar tetapi masih menunggu
     * pemasok belum boleh terbaca "Selesai".
     */
    protected function postedLabel(): string
    {
        return $this->tracksSupplierReturn() && ! $this->isReturnClosed() ? 'Diproses' : 'Selesai';
    }
}
