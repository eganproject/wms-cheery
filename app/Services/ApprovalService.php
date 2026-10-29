<?php

namespace App\Services;

use App\Models\DamagedDisposal;
use App\Models\Inbound;
use App\Models\Outbound;
use App\Models\ReturnReceipt;
use App\Models\StockAdjustment;
use App\Models\StockOpname;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Alur persetujuan dokumen gudang.
 *
 * Stok baru bergerak saat dokumen disetujui, jadi persetujuan sekaligus
 * menjadi otorisasi atas perubahan stok.
 */
class ApprovalService
{
    public function __construct(protected StockService $stock)
    {
    }

    /**
     * Ajukan dokumen untuk disetujui.
     */
    public function submit(Model $document): void
    {
        DB::transaction(function () use ($document) {
            $this->lockFresh($document);
            $this->guardEditable($document);
            $this->guardReady($document);

            $document->forceFill([
                'status' => $document::STATUS_PENDING,
                'submitted_at' => now(),
                'submitted_by' => auth()->id(),
                'rejected_at' => null,
                'rejected_by' => null,
                'rejection_reason' => null,
            ])->save();
        });
    }

    /**
     * Setujui dokumen: stok langsung bergerak dalam transaksi yang sama.
     */
    public function approve(Model $document): void
    {
        DB::transaction(fn () => $this->approveLocked($document));
    }

    /**
     * Isi approve(), dijalankan di dalam transaksi dengan baris dokumen terkunci.
     */
    protected function approveLocked(Model $document): void
    {
        $this->lockFresh($document);

        if ($document->status === $document::STATUS_POSTED) {
            throw ValidationException::withMessages([
                'status' => 'Dokumen ini sudah disetujui sebelumnya.',
            ]);
        }

        /*
            Hanya dokumen yang benar-benar diajukan yang bisa disetujui.

            Tanpa penjagaan ini, satu permintaan langsung ke alamat persetujuan
            memposting dokumen yang masih draft: stoknya bergerak sementara
            kolom "diajukan oleh" tetap kosong, dan jejak auditnya berbohong.
            Yang ditolak pun bisa dilompati perbaikannya.

            Menyetujui langsung tanpa antre tetap bisa — lewat submitAndApprove,
            yang mencatat pengajunya lebih dulu.
        */
        if (! $document->isPending()) {
            throw ValidationException::withMessages([
                'status' => 'Hanya dokumen yang sedang diajukan dapat disetujui.',
            ]);
        }

        $this->guardReady($document);

        DB::transaction(function () use ($document) {
            $document->forceFill([
                'approved_at' => now(),
                'approved_by' => auth()->id(),
                'rejected_at' => null,
                'rejected_by' => null,
                'rejection_reason' => null,
            ])->save();

            // StockService yang menetapkan status akhir menjadi diposting.
            match (true) {
                $document instanceof Inbound => $this->stock->postInbound($document),
                $document instanceof Outbound => $this->stock->postOutbound($document),
                $document instanceof ReturnReceipt => $this->stock->postReturn($document),
                $document instanceof StockAdjustment => $this->stock->postAdjustment($document),
                $document instanceof StockOpname => $this->stock->postOpname($document),
                $document instanceof DamagedDisposal => $this->stock->postDisposal($document),
            };
        });
    }

    /**
     * Setujui langsung tanpa antrean — dipakai pengguna yang memang berwenang.
     */
    public function submitAndApprove(Model $document): void
    {

        /*
            Dokumen benar-benar melewati status diajukan, bukan melompatinya —
            itulah yang membuat penjagaan pada approve() bisa berlaku untuk
            semua jalur sekaligus.

            Dibungkus transaksi supaya kegagalan di tengah, misalnya stok yang
            keburu habis, mengembalikan dokumen ke draft alih-alih
            meninggalkannya menggantung sebagai pengajuan yang tak seorang pun
            merasa membuatnya.
        */
        DB::transaction(function () use ($document) {
            // Tanpa ini, dokumen yang sudah diposting bisa diposting ulang
            // lewat klik ganda atau form yang dikirim ulang: statusnya
            // ditimpa menjadi diajukan lalu stoknya bergerak dua kali.
            $this->lockFresh($document);
            $this->guardEditable($document);
            $this->guardReady($document);

            $document->forceFill([
                'status' => $document::STATUS_PENDING,
                'submitted_at' => now(),
                'submitted_by' => auth()->id(),
            ])->save();

            $this->approveLocked($document);
        });
    }

    /**
     * Tolak dokumen dan kembalikan ke penyusunnya.
     */
    public function reject(Model $document, string $reason): void
    {
        DB::transaction(function () use ($document, $reason) {
            $this->lockFresh($document);

            if (! $document->isPending()) {
                throw ValidationException::withMessages([
                    'status' => 'Hanya dokumen yang sedang diajukan dapat ditolak.',
                ]);
            }

            $document->forceFill([
                'status' => $document::STATUS_REJECTED,
                'rejected_at' => now(),
                'rejected_by' => auth()->id(),
                'rejection_reason' => $reason,
                'approved_at' => null,
                'approved_by' => null,
            ])->save();
        });
    }

    /**
     * Tarik kembali pengajuan menjadi draft.
     */
    public function withdraw(Model $document): void
    {
        DB::transaction(function () use ($document) {
            $this->lockFresh($document);

            if (! $document->isPending()) {
                throw ValidationException::withMessages([
                    'status' => 'Hanya dokumen yang sedang diajukan dapat ditarik kembali.',
                ]);
            }

            $document->forceFill([
                'status' => $document::STATUS_DRAFT,
                'submitted_at' => null,
                'submitted_by' => null,
            ])->save();
        });
    }

    /**
     * Kunci baris dokumen dan baca ulang statusnya dari basis data.
     *
     * Model yang dipegang pemanggil bisa sudah basi: dua klik pada tombol
     * yang sama, atau dua orang di dua layar, sama-sama memuat dokumen saat
     * masih draft. Tanpa kunci keduanya lolos pemeriksaan status dan stoknya
     * bergerak dua kali. Relasi yang sudah dimuat dibiarkan.
     */
    protected function lockFresh(Model $document): void
    {
        $fresh = $document->newQuery()->lockForUpdate()->find($document->getKey());

        if ($fresh) {
            $document->setRawAttributes($fresh->getAttributes(), true);
        }
    }

    protected function guardEditable(Model $document): void
    {
        if (! $document->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'Dokumen ini tidak lagi berstatus draft.',
            ]);
        }
    }

    /**
     * Syarat isi dokumen tetap berlaku, termasuk verifikasi scan marketplace.
     */
    protected function guardReady(Model $document): void
    {
        $document->loadMissing('items');

        if ($document->items->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'Dokumen belum memiliki baris barang.',
            ]);
        }

        if (method_exists($document, 'postingBlockers') && $blockers = $document->postingBlockers()) {
            throw ValidationException::withMessages(['status' => $blockers]);
        }
    }
}
