<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\InstructorResource\Pages;
use App\Filament\Resources\InstructorResource\RelationManagers\PayoutsRelationManager;
use App\Filament\Support\MinorUnits;
use App\Filament\Support\PendingRecognition;
use App\Models\InstructorBalance;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * §13: the read model. "The required read-only screen reads the cache, never the
 * ledger. Recomputing millions of rows on a page load would defeat §6.4 and
 * §10.5."
 *
 * The resource is backed by instructor_balances, not by an Instructor model,
 * because §14 gives instructor_id no parent table in this scope. The balance row
 * IS the instructor as far as this system is concerned, and it is a single
 * indexed lookup by primary key.
 *
 * Read-only is enforced here rather than left to a policy: §6 rule "do not add
 * anything not in the doc" rules out an edit path, and a money cache that a
 * human can type into is a reconciliation finding waiting to happen (§10.5 —
 * the cache is maintained by the transactions that move the ledger rows, never
 * by anything else).
 */
class InstructorResource extends Resource
{
    protected static ?string $model = InstructorBalance::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $modelLabel = 'instructor';

    protected static ?string $pluralModelLabel = 'instructors';

    protected static ?string $recordTitleAttribute = 'instructor_id';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * §13: every column here is a column of instructor_balances. Nothing on this
     * table aggregates ledger_entries — `pending` is the one derived figure and
     * it lives on the single-record page, because computing it per row would
     * turn a list into one allocation sweep per instructor (§19).
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('instructor_id')
                    ->label('Instructor')
                    ->sortable()
                    ->searchable(),
                self::moneyColumn('recognized_minor', 'Recognized'),
                // §1: available may be negative, and that is debt rather than a
                // bug (§10.4). It is coloured, not hidden or clamped.
                self::moneyColumn('available_minor', 'Available')
                    ->color(fn (int $state): string => $state < 0 ? 'danger' : 'gray'),
                self::moneyColumn('reserved_minor', 'Reserved'),
                self::moneyColumn('paid_minor', 'Paid'),
                TextColumn::make('recognized_through_at')
                    ->label('Watermark (UTC)')
                    ->dateTime('Y-m-d H:i')
                    // §3.3: displayed in UTC, because that is how it is stored
                    // and compared. Rendering it in a viewer's timezone would
                    // show a recognition boundary that is not the one the
                    // system used, and the label says so.
                    ->timezone('UTC')
                    // Null until the first release run posts for this
                    // instructor (§6.2) — a real state, not missing data.
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('instructor_id')
            ->actions([
                ViewAction::make(),
            ])
            // §6 rule: nothing here creates, edits or deletes. No bulk actions
            // either — a bulk delete on the serialisation point would take the
            // ledger's RESTRICT foreign keys with it (§14).
            ->bulkActions([]);
    }

    /**
     * §13's Balance block, in order. Five reads off the single row, plus
     * `pending`.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Balance')
                    ->description('instructor_balances — a single indexed row (§13)')
                    ->columns(3)
                    ->schema([
                        self::moneyEntry('recognized_minor', 'Recognized')
                            // §1.1: recognized covers ALL entry types,
                            // refund_adjustment included.
                            ->helperText('Σ all entry types (§1.1)'),
                        // §15 invariant 3: recognized == available + reserved
                        // + paid. The three buckets are shown together so that
                        // identity is readable off the screen.
                        self::moneyEntry('available_minor', 'Available')
                            ->helperText('payable now; may be negative (§10.4)')
                            ->color(fn (int $state): string => $state < 0 ? 'danger' : 'gray'),
                        self::moneyEntry('reserved_minor', 'Reserved')
                            ->helperText('claimed by an in-flight payout'),
                        self::moneyEntry('paid_minor', 'Paid')
                            ->helperText('claimed by a settled payout'),
                        TextEntry::make('recognized_through_at')
                            ->label('Watermark (UTC)')
                            ->helperText('recognition posted through (§6.2)')
                            // §3.3: UTC, as stored and compared.
                            ->dateTime('Y-m-d H:i:s')
                            ->timezone('UTC')
                            ->placeholder('—'),
                        TextEntry::make('pending')
                            ->label('Pending')
                            ->helperText('Σ released(alloc, now) − posted (§6.5) — never payable')
                            ->fontFamily(FontFamily::Mono)
                            // §13: the only figure on this screen that is
                            // computed rather than read.
                            ->getStateUsing(fn (InstructorBalance $record): int => PendingRecognition::forInstructor(
                                $record->instructor_id,
                            ))
                            ->formatStateUsing(fn (int $state): string => MinorUnits::format($state)),
                    ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            PayoutsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInstructors::route('/'),
            'view' => Pages\ViewInstructor::route('/{record}'),
        ];
    }

    /** §3: integer minor units, formatted without a float. */
    private static function moneyColumn(string $name, string $label): TextColumn
    {
        return TextColumn::make($name)
            ->label($label)
            ->formatStateUsing(fn (int $state): string => MinorUnits::format($state))
            ->fontFamily(FontFamily::Mono)
            ->alignEnd()
            ->sortable();
    }

    /** §3: integer minor units, formatted without a float. */
    private static function moneyEntry(string $name, string $label): TextEntry
    {
        return TextEntry::make($name)
            ->label($label)
            ->formatStateUsing(fn (int $state): string => MinorUnits::format($state))
            ->fontFamily(FontFamily::Mono);
    }
}
