<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorResource\Pages;

use App\Filament\Resources\InstructorResource;
use App\Models\InstructorBalance;
use Filament\Resources\Pages\ViewRecord;

/**
 * §13: the Balance block (the infolist on InstructorResource) followed by the
 * payout history (the relation manager). No edit action — see the note on
 * InstructorResource about why the cache is not typeable.
 */
class ViewInstructor extends ViewRecord
{
    protected static string $resource = InstructorResource::class;

    public function getTitle(): string
    {
        /** @var InstructorBalance $record */
        $record = $this->getRecord();

        // §14: instructor_id has no parent table, so there is no name to show.
        return 'Instructor #'.$record->instructor_id;
    }
}
