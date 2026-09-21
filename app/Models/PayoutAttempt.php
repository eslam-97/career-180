<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoutAttempt extends Model
{
    use HasFactory;

    // §10.2: active_payout_id is a STORED generated column. MySQL rejects any
    // write to it, so it must never reach an insert or update.
    protected $guarded = ['active_payout_id'];

    protected function casts(): array
    {
        return [
            'attempt_no' => 'integer',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'started_at' => 'immutable_datetime',
            // §11.1: once this passes, the sweeper's only legal transition is
            // sending -> unknown. It never marks an attempt failed.
            'lease_expires_at' => 'immutable_datetime',
            'polled_at' => 'immutable_datetime',
            'poll_count' => 'integer',
            'active_payout_id' => 'integer',
        ];
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }
}
