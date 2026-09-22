<?php

declare(strict_types=1);

namespace App\Domain\Payout;

use RuntimeException;

/**
 * §10.4: "sum <= 0 → ROLLBACK, no payout row, entries stay unclaimed".
 *
 * Throwing is how the whole transaction is discarded. It is control flow, not a
 * failure: ClaimService catches it immediately outside its own transaction and
 * reports "no payout" to the caller. It exists as a named type so it can never
 * be mistaken for a real error, and so nothing above can catch it by accident.
 */
final class NonPositiveClaim extends RuntimeException {}
