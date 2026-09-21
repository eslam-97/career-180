<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayoutBatch extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'cutoff_at' => 'immutable_datetime',
            // §10.1: frozen at batch creation — the other half of what makes a
            // replayed batch claim an identical entry set.
            'max_entry_id' => 'integer',
        ];
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class, 'batch_id');
    }
}
