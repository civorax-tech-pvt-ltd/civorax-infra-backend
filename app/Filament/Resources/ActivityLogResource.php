<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ActivityLogResource\Pages;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Who created, changed or deleted what, and when, with the old and new values. Read-only, super admins only.
 */
class ActivityLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Team';

    protected static ?int $navigationSort = 90;

    protected static ?string $navigationLabel = 'Activity log';

    protected static ?string $modelLabel = 'activity';

    protected static ?string $pluralModelLabel = 'activity log';

    public static function canAccess(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin' && (bool) auth()->user()?->hasRole('super_admin');
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

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
     * "App\Models\PurchaseBill" → "Purchase bill".
     */
    public static function typeLabel(?string $type): string
    {
        return $type ? Str::of(class_basename($type))->snake(' ')->ucfirst()->toString() : '—';
    }

    public static function recordLabel(Activity $activity): string
    {
        $subject = $activity->subject;
        $name = $subject
            ? ($subject->title ?? $subject->name ?? $subject->fullname ?? $subject->contact_person ?? $subject->description ?? $subject->number ?? $subject->bill_no ?? null)
            : ($activity->properties['old']['title'] ?? $activity->properties['old']['name'] ?? null);

        return Str::limit(trim(($name ? (string) $name.' ' : '').'#'.$activity->subject_id), 70);
    }

    /**
     * @return list<array{field: string, old: string, new: string}>
     */
    public static function changes(Activity $activity): array
    {
        $old = (array) ($activity->properties['old'] ?? []);
        $new = (array) ($activity->properties['attributes'] ?? []);

        return collect(array_unique([...array_keys($new), ...array_keys($old)]))
            ->map(fn (string $field): array => [
                'field' => Str::of($field)->replace('_id', '')->replace('_', ' ')->ucfirst()->toString(),
                'old' => static::display($old[$field] ?? null),
                'new' => static::display($new[$field] ?? null),
            ])
            ->values()
            ->all();
    }

    protected static function display(mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' => '—',
            is_bool($value) => $value ? 'Yes' : 'No',
            is_array($value) => Str::limit(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200),
            default => Str::limit((string) $value, 200),
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['causer', 'subject']))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('M j, Y g:i A')
                    ->description(fn (Activity $record): string => $record->created_at->diffForHumans()),
                Tables\Columns\TextColumn::make('causer.name')
                    ->label('By')
                    ->placeholder('System'),
                Tables\Columns\TextColumn::make('event')
                    ->label('Action')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ucfirst($state ?? 'note'))
                    ->color(fn (?string $state): string => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('subject_type')
                    ->label('Record')
                    ->formatStateUsing(fn (?string $state): string => static::typeLabel($state))
                    ->description(fn (Activity $record): string => static::recordLabel($record)),
                Tables\Columns\TextColumn::make('changed')
                    ->label('Changed')
                    ->state(fn (Activity $record): string => $record->event === 'updated'
                        ? collect(static::changes($record))->pluck('field')->implode(', ')
                        : '')
                    ->limit(60)
                    ->wrap()
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('subject_type')
                    ->label('Record type')
                    ->options(fn (): array => Activity::query()->distinct()->orderBy('subject_type')->pluck('subject_type')
                        ->filter()
                        ->mapWithKeys(fn (string $type): array => [$type => static::typeLabel($type)])
                        ->all())
                    ->searchable(),
                Tables\Filters\SelectFilter::make('causer_id')
                    ->label('By')
                    ->options(fn (): array => User::query()->whereIn('id', Activity::query()->distinct()->pluck('causer_id')->filter())->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Tables\Filters\SelectFilter::make('event')
                    ->label('Action')
                    ->options(['created' => 'Created', 'updated' => 'Updated', 'deleted' => 'Deleted', 'restored' => 'Restored']),
                Tables\Filters\Filter::make('date')
                    ->form([
                        Forms\Components\DatePicker::make('from'),
                        Forms\Components\DatePicker::make('until'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->actions([
                Tables\Actions\Action::make('details')
                    ->label('Details')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (Activity $record): string => ucfirst($record->event ?? 'activity').' · '.static::typeLabel($record->subject_type).' · '.static::recordLabel($record))
                    ->modalDescription(fn (Activity $record): string => 'By '.($record->causer?->name ?? 'System').' on '.$record->created_at->format('M j, Y g:i A'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (Activity $record) => view('filament.activity-log.changes', ['changes' => static::changes($record), 'event' => $record->event])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivityLogs::route('/'),
        ];
    }
}
