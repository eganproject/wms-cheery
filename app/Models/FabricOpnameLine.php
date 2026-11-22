<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FabricOpnameLine extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'fabric_opname_id', 'fabric_roll_id', 'system_quantity', 'counted_quantity', 'applied_difference', 'counted_at', 'counted_by',
    ];

    protected function casts(): array
    {
        return [
            'system_quantity' => 'float',
            'counted_quantity' => 'float',
            'applied_difference' => 'float',
            'counted_at' => 'datetime',
        ];
    }

    public function opname(): BelongsTo
    {
        return $this->belongsTo(FabricOpname::class, 'fabric_opname_id');
    }

    public function roll(): BelongsTo
    {
        return $this->belongsTo(FabricRoll::class, 'fabric_roll_id');
    }

    public function difference(): ?float
    {
        return $this->counted_quantity === null ? null : round($this->counted_quantity - $this->system_quantity, 2);
    }
}
