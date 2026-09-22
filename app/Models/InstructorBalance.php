<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * §9.4: the per-instructor serialisation point. Every money-moving transaction
 * takes this row FOR UPDATE before touching that instructor's ledger entries,
 * payouts or attempts.
 *
 * §10.5: the cache is maintained by those transactions, never by
 * reconciliation. Every update is `SET col = col + ?`, never a read-modify-write
 * in PHP — so nothing here exposes a setter that would invite one.
 */
class InstructorBalance extends Model
{
    use HasFactory;

    // §14: keyed by the instructor id itself, not a surrogate.
    protected $primaryKey = 'instructor_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'instructor_id' => 'integer',
            // §1.1: recognized covers ALL entry types, refund_adjustment
            // included, because §12's identity runs over all of them.
            'recognized_minor' => 'integer',
            // §10.4: may be negative — debt lives here and nets against future
            // recognition with no manual step.
            'available_minor' => 'integer',
            'reserved_minor' => 'integer',
            'paid_minor' => 'integer',
            // §1.1: the watermark.
            'recognized_through_at' => 'immutable_datetime',
        ];
    }

    /**
     * §13: the payout history half of the read model. instructor_id has no
     * parent table (§14), so the balance row — which IS keyed by it — is the
     * only place this relation can hang.
     */
    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class, 'instructor_id', 'instructor_id');
    }
}
