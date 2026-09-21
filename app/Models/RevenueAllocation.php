<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RevenueAllocation extends Model
{
    use HasFactory;

    // §5.3: an allocation is frozen at payment and never changes, so there is
    // nothing for an updated_at to record.
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            // §5.3: the canonical entitlement, and the thing any reversal
            // splits by — never re-derived from the weights (§3.1).
            'amount_minor' => 'integer',
            'weight_numerator' => 'integer',
            'weight_denominator' => 'integer',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPayment::class, 'payment_id');
    }
}
