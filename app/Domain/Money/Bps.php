<?php

declare(strict_types=1);

namespace App\Domain\Money;

use InvalidArgumentException;

/**
 * Basis-point arithmetic for the platform cut.
 *
 * §3: rates are basis points (a whole number), never a decimal rate.
 * §5.4: the instructor pool is floored and the platform cut is the residue.
 */
final class Bps
{
    // §3: 100% expressed in basis points. 20% is 2000, 7.5% is 750.
    public const SCALE = 10_000;

    /**
     * The instructor pool: floor(gross * (10000 - bps) / 10000).
     *
     * §5.4: the pool is what gets floored. Deriving the cut this way rather
     * than the other way round is what makes the platform the residual
     * claimant at allocation time as well as at release time (§8).
     */
    public static function pool(int $gross, int $bps): int
    {
        self::guard($gross, $bps);

        // §3: integer division only. intdiv() equals floor() here precisely
        // because the numerator is non-negative, which guard() enforces.
        return intdiv($gross * (self::SCALE - $bps), self::SCALE);
    }

    /**
     * The platform cut: gross - pool.
     *
     * §5.4: never computed directly as floor(gross * bps / 10000). That
     * alternative also sums exactly but hands the sub-piastre residue to
     * instructors, contradicting §8.
     */
    public static function cut(int $gross, int $bps): int
    {
        return $gross - self::pool($gross, $bps);
    }

    private static function guard(int $gross, int $bps): void
    {
        // §3: subscription_payments.amount_minor is BIGINT UNSIGNED.
        if ($gross < 0) {
            throw new InvalidArgumentException("Gross must be a non-negative magnitude, got {$gross}.");
        }

        // §3: platform_rate_bps is SMALLINT UNSIGNED with CHECK (<= 10000).
        if ($bps < 0 || $bps > self::SCALE) {
            throw new InvalidArgumentException('Rate must be between 0 and '.self::SCALE." basis points, got {$bps}.");
        }
    }
}
