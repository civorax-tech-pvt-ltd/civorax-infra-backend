<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AttendanceResource\Pages;
use App\Filament\Resources\Concerns\ScopesToTeamMember;
use App\Models\Attendance;
use App\Models\TeamMember;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class AttendanceResource extends Resource
{
    use ScopesToTeamMember;

    protected static ?string $model = Attendance::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationGroup = 'Team';

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return static::isAdminPanel() ? 'Attendance' : 'My Attendance';
    }

    public static function getModelLabel(): string
    {
        return 'attendance';
    }

    public static function getPluralModelLabel(): string
    {
        return static::getNavigationLabel();
    }

    public static function canViewAny(): bool
    {
        return static::isAdminPanel() || auth()->user()?->teamMember !== null;
    }

    public static function canCreate(): bool
    {
        return static::isAdminPanel();
    }

    public static function canEdit(Model $record): bool
    {
        return static::isAdminPanel();
    }

    public static function canDelete(Model $record): bool
    {
        return static::isAdminPanel();
    }

    public static function form(Form $form): Form
    {
        $timezone = config('app.business_timezone');

        return $form
            ->schema([
                Forms\Components\Select::make('team_member_id')
                    ->label('Team member')
                    ->relationship('teamMember', 'fullname')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->disabledOn('edit'),
                Forms\Components\DateTimePicker::make('first_seen_at')
                    ->label('Check in')
                    ->seconds(false)
                    ->timezone($timezone)
                    ->default(now())
                    ->required()
                    ->rules([
                        fn (Get $get, ?Attendance $record): Closure => function (string $attribute, $value, Closure $fail) use ($get, $record, $timezone): void {
                            $date = Carbon::parse($value, $timezone)->toDateString();
                            $exists = Attendance::query()
                                ->where('team_member_id', $get('team_member_id') ?? $record?->team_member_id)
                                ->whereDate('date', $date)
                                ->when($record, fn (Builder $query) => $query->whereKeyNot($record->getKey()))
                                ->exists();

                            if ($exists) {
                                $fail('This team member already has attendance for that day. Edit that entry instead.');
                            }
                        },
                    ]),
                Forms\Components\DateTimePicker::make('last_seen_at')
                    ->label('Check out / last seen')
                    ->seconds(false)
                    ->timezone($timezone)
                    ->default(now())
                    ->required()
                    ->afterOrEqual('first_seen_at'),
                Forms\Components\Textarea::make('note')
                    ->label('Reason for manual entry')
                    ->placeholder('e.g. Phone battery died at site, confirmed by supervisor.')
                    ->required(fn (?Attendance $record): bool => $record === null || $record->source === 'manual')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        $timezone = config('app.business_timezone');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['teamMember', 'visits.project', 'visits.officeLocation']))
            ->columns([
                Tables\Columns\TextColumn::make('date')
                    ->date('D, M j, Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('teamMember.fullname')
                    ->label('Team member')
                    ->searchable()
                    ->sortable()
                    ->visible(fn (): bool => static::isAdminPanel()),
                Tables\Columns\TextColumn::make('first_seen_at')
                    ->label('Check in')
                    ->time('g:i A')
                    ->timezone($timezone),
                Tables\Columns\TextColumn::make('last_seen_at')
                    ->label('Last seen')
                    ->time('g:i A')
                    ->timezone($timezone),
                Tables\Columns\TextColumn::make('hours')
                    ->state(fn (Attendance $record): string => $record->hoursOnDuty().' h'),
                Tables\Columns\TextColumn::make('places')
                    ->label('Sites / office visited')
                    ->state(fn (Attendance $record): string => $record->placesSummary())
                    ->wrap(),
                Tables\Columns\TextColumn::make('last_location')
                    ->label('Last location')
                    ->state(fn (Attendance $record): ?string => ($ping = $record->latestPing())
                        ? $ping->recorded_at->timezone($timezone)->format('g:i A').' · ±'.$ping->accuracy.' m'
                        : null)
                    ->icon('heroicon-o-map')
                    ->color('primary')
                    ->url(fn (Attendance $record): ?string => $record->latestPing()?->mapUrl(), shouldOpenInNewTab: true)
                    ->tooltip('Open in Google Maps')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('source')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'manual' ? 'Manual' : 'GPS')
                    ->color(fn (string $state): string => $state === 'manual' ? 'warning' : 'success')
                    ->tooltip(fn (Attendance $record): ?string => $record->note),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('team_member_id')
                    ->label('Team member')
                    ->relationship('teamMember', 'fullname')
                    ->searchable()
                    ->preload()
                    ->visible(fn (): bool => static::isAdminPanel()),
                Tables\Filters\Filter::make('date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->default(now()->startOfMonth()),
                        Forms\Components\DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->whereDate('date', '<=', $date)))
                    ->indicateUsing(fn (array $data): ?string => ($data['from'] ?? null) || ($data['until'] ?? null)
                        ? 'Dates: '.($data['from'] ?? '…').' → '.($data['until'] ?? 'today')
                        : null),
                Tables\Filters\SelectFilter::make('source')
                    ->options(['gps' => 'GPS', 'manual' => 'Manual']),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $timezone = config('app.business_timezone');

        return $infolist
            ->columns(4)
            ->schema([
                Infolists\Components\TextEntry::make('teamMember.fullname')->label('Team member'),
                Infolists\Components\TextEntry::make('date')->date('D, M j, Y'),
                Infolists\Components\TextEntry::make('first_seen_at')->label('Check in')->time('g:i A')->timezone($timezone),
                Infolists\Components\TextEntry::make('last_seen_at')->label('Last seen')->time('g:i A')->timezone($timezone),
                Infolists\Components\TextEntry::make('note')->columnSpanFull()->placeholder('—'),
                Infolists\Components\RepeatableEntry::make('visits')
                    ->label('Places visited')
                    ->columns(5)
                    ->columnSpanFull()
                    ->schema([
                        Infolists\Components\TextEntry::make('place')
                            ->state(fn ($record): string => $record->placeName())
                            ->icon('heroicon-o-map')
                            ->color('primary')
                            ->url(fn ($record): ?string => $record->mapUrl(), shouldOpenInNewTab: true)
                            ->columnSpan(2),
                        Infolists\Components\TextEntry::make('first_seen_at')->label('Arrived')->time('g:i A')->timezone($timezone),
                        Infolists\Components\TextEntry::make('last_seen_at')->label('Last seen')->time('g:i A')->timezone($timezone),
                        Infolists\Components\TextEntry::make('closest_distance')->label('Closest')->suffix(' m'),
                    ]),
                Infolists\Components\ViewEntry::make('location_history')
                    ->label('Location history')
                    ->view('filament.infolists.location-history')
                    ->state(fn (Attendance $record) => $record->dayPings()->get())
                    ->columnSpanFull(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scopeToTeamMember(
            parent::getEloquentQuery(),
            fn (Builder $query, TeamMember $teamMember) => $query->where('team_member_id', $teamMember->getKey()),
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAttendances::route('/'),
            'create' => Pages\CreateAttendance::route('/create'),
            'edit' => Pages\EditAttendance::route('/{record}/edit'),
        ];
    }

    protected static function isAdminPanel(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin';
    }
}
