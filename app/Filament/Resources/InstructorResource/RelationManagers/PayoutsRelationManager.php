<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorResource\RelationManagers;

use App\Filament\Support\MinorUnits;
use App\Models\Payout;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * §13: "Payout history ← payouts joined to payout_batches". Columns: batch
 * period, amount, status, settled_at, attempts (count, with last provider
 * reference).
 *
 * The join is the whole query. Nothing here touches ledger_entries — §13's
 * drill-down to the claimed entries of a specific payout is the audit path
 * reached through payout_id (§10.1), not something this screen aggregates.
 */
class PayoutsRelationManager extends RelationManager
{
    protected static string $relationship = 'payouts';

    protected static ?string $title = 'Payout history';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                // §13: batch period. Dot notation makes Filament eager-load
                // payout_batches, which is the "joined to payout_batches" half
                // of the read — one extra query for the page, not one per row.
                TextColumn::make('batch.period_start')
                    ->label('Batch period')
                    ->formatStateUsing(fn (Payout $record): string => $record->batch->period_start->format('Y-m-d')
                        .' → '
                        .$record->batch->period_end->format('Y-m-d'))
                    ->sortable(),
                TextColumn::make('amount_minor')
                    ->label('Amount')
                    // §10.4 / invariant 17: every payout row visible to another
                    // transaction has amount_minor > 0. The NULL exists only
                    // inside the uncommitted claim transaction, so this screen
                    // can never see one — the placeholder is here because the
                    // column is nullable, not because the state is expected.
                    ->formatStateUsing(fn (int $state): string => MinorUnits::format($state))
                    ->placeholder('—')
                    ->fontFamily(FontFamily::Mono)
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    // §10.2: pending → in_progress → settled | failed |
                    // needs_review. needs_review is non-terminal (invariant 24)
                    // and is a human decision by design (§19), so it is warned
                    // rather than coloured like a plain failure.
                    ->color(fn (string $state): string => match ($state) {
                        'settled' => 'success',
                        'failed' => 'danger',
                        'needs_review' => 'warning',
                        'in_progress' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('settled_at')
                    ->label('Settled at (UTC)')
                    // §3.3: UTC, as stored and compared.
                    ->dateTime('Y-m-d H:i:s')
                    ->timezone('UTC')
                    // §10.2: null for every status but settled.
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('attempt_count')
                    ->label('Attempts')
                    // §10.2 / invariant 19: attempt_count increments if and
                    // only if a worker acquired the slot, so this is the count
                    // of provider calls committed to, not of rows that happen
                    // to exist.
                    ->alignEnd()
                    ->sortable(),
                // §13: "count, with last provider reference". Dot notation
                // again, so Filament eager-loads latestAttempt — one extra
                // statement for the page however many payouts there are.
                //
                // Blank when the latest attempt has not yet had an answer from
                // the provider. An older attempt's reference is not this
                // attempt's reference, and showing it would send an operator to
                // the wrong transfer (§10.2 — a new attempt means a new
                // idempotency key and a genuinely separate provider call).
                TextColumn::make('latestAttempt.provider_reference')
                    ->label('Last provider reference')
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('—'),
            ])
            // §10.1: the newest batch first is what an operator wants; the tie
            // break on id keeps the order total.
            ->defaultSort('id', 'desc')
            // §13: read-only. No create, attach, edit, delete or bulk action —
            // a payout's state is moved by the settlement path (§10.3) and by
            // the sweepers (§10.7, §11.1), never by a person on a screen.
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
