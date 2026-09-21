<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory;

    // §13: the Filament panel is read-only and rule 6 forbids API routes, so
    // there is no request-driven mass-assignment surface. Every write comes
    // from a domain service.
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            // §3: money is always int, never a numeric string off the wire.
            'amount_minor' => 'integer',
            // §3.3: UTC, and immutable so the value can be handed straight to
            // ReleaseCalculator without conversion.
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'access_ends_at' => 'immutable_datetime',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }
}
