<?php

declare(strict_types=1);

namespace App\Filament\Support;

/**
 * §3: all money is integer minor units (piastres). Display is the one place a
 * float would look harmless, so it is spelled out here that none is used —
 * `number_format()` takes a float parameter and silently loses precision above
 * 2^53, which a BIGINT column can exceed. The grouping below runs on the digit
 * string instead.
 *
 * §3.2: a single settlement currency, and downstream tables carry no currency
 * column. No symbol is printed, because printing one for money whose currency
 * this table does not record would be exactly the decoration §3.2 rejects.
 */
final class MinorUnits
{
    private const MINOR_PER_MAJOR = 100;

    private const MINOR_DIGITS = 2;

    public static function format(int $minor): string
    {
        // §3: split the magnitude and carry the sign separately. intdiv()
        // rounds toward zero, so applying it to a negative value directly would
        // put the piastres on the wrong side of the point (-1 piastre would
        // render as "0.-1" rather than "-0.01"). Availability is legitimately
        // negative (§10.4), so this path is reached in normal operation.
        $negative = $minor < 0;
        $magnitude = $negative ? -$minor : $minor;

        $major = intdiv($magnitude, self::MINOR_PER_MAJOR);
        $piastres = $magnitude % self::MINOR_PER_MAJOR;

        return ($negative ? '-' : '')
            .self::group((string) $major)
            .'.'
            .str_pad((string) $piastres, self::MINOR_DIGITS, '0', STR_PAD_LEFT);
    }

    /** Thousands separators applied to the digit string, so no float is involved. */
    private static function group(string $digits): string
    {
        return strrev(implode(',', str_split(strrev($digits), 3)));
    }
}
