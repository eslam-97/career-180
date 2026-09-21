<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPayment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            // §5.2: the distinct instructors the subscription grants access to,
            // frozen at payment time and read back by the allocation job.
            'instructor_ids' => 'array',
            // §3: basis points are a whole number, never a decimal rate.
            'platform_rate_bps' => 'integer',
            'platform_cut_minor' => 'integer',
            'term_start' => 'immutable_datetime',
            'term_end' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
        ];
    }

    /**
     * §5.1: confirmed means the provider gave us a reference. One definition,
     * used by both the allocation service and the sweeper that feeds it — if
     * these two ever disagreed, the sweeper would dispatch jobs the service
     * refuses, forever.
     */
    public function isConfirmed(): bool
    {
        return $this->provider_reference !== null;
    }

    /** @param  Builder<SubscriptionPayment>  $query */
    public function scopeConfirmed(Builder $query): void
    {
        $query->whereNotNull('provider_reference');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(RevenueAllocation::class, 'payment_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class, 'payment_id');
    }
}
