<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    // §10.1: the audit path — the ledger entries this payout claimed.
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
