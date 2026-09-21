<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    use HasFactory;

    // A ledger row's only mutable field is payout_id, and that history lives on
    // payouts and payout_attempts.
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            // §3: SIGNED — corrections and adjustments are compensating entries.
            'amount_minor' => 'integer',
            // §3.3: three dates, three jobs. period_start is a posting period
            // (§6.3), so it is a date; the other two are instants.
            'period_start' => 'immutable_date',
            'recognized_through_at' => 'immutable_datetime',
            'effective_at' => 'immutable_datetime',
        ];
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }
}
