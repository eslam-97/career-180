<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorResource\Pages;

use App\Filament\Resources\InstructorResource;
use Filament\Resources\Pages\ListRecords;

/**
 * §13: read-only. No header actions — there is no create path, because an
 * instructor_balances row is brought into existence by allocation (§9.4) and by
 * nothing else.
 */
class ListInstructors extends ListRecords
{
    protected static string $resource = InstructorResource::class;
}
