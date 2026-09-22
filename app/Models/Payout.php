<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payout extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            // §10.4: nullable only inside the uncommitted claim transaction.
            'amount_minor' => 'integer',
            // §10.2: increments if and only if a worker acquires the slot.
            'attempt_count' => 'integer',
            'settled_at' => 'immutable_datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PayoutBatch::class, 'batch_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PayoutAttempt::class);
    }

    /**
     * §13: "attempts — count, with last provider reference". Keyed on
     * attempt_no rather than id because UNIQUE(payout_id, attempt_no) is what
     * defines the ordering of attempts (§14); id only happens to agree today.
     *
     * Read model only — it exists so the history table eager-loads one row per
     * payout instead of querying per row. Nothing on the money path uses it;
     * §10.2's slot gate reads attempts under the balance lock.
     */
    public function latestAttempt(): HasOne
    {
        return $this->hasOne(PayoutAttempt::class)->latestOfMany('attempt_no');
    }

    // §10.1: the audit path — the ledger entries this payout claimed.
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
