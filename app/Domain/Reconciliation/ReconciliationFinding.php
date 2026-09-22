<?php

declare(strict_types=1);

namespace App\Domain\Reconciliation;

/**
 * §12: one detected drift. A finding is a *record that something is wrong*, not
 * a repair — reconciliation fails loudly rather than repairing silently,
 * because "a system that quietly repairs itself hides the bug that caused the
 * problem".
 */
final class ReconciliationFinding
{
    /** @param  array<string, mixed>  $detail */
    public function __construct(
        public readonly string $kind,
        public readonly string $subjectType,
        public readonly int $subjectId,
        public readonly array $detail,
    ) {}

    public function summary(): string
    {
        return sprintf(
            '%s on %s %d: %s',
            $this->kind,
            $this->subjectType,
            $this->subjectId,
            json_encode($this->detail, JSON_THROW_ON_ERROR),
        );
    }
}
