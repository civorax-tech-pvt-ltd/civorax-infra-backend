<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Concerns\SiteAccess;
use App\Filament\Resources\SiteReportResource\Pages;
use App\Models\Labourer;
use App\Models\MusterRoll;
use App\Models\SiteReport;
use App\Models\Task;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class SiteReportResource extends Resource
{
    use SiteAccess;

    protected static ?string $model = SiteReport::class;

    protected static ?string $navigationIcon = 'heroicon-o-camera';

    protected static ?string $navigationGroup = 'Site';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Daily Site Reports';

    protected static ?string $modelLabel = 'site report';

    public static function canViewAny(): bool
    {
        return static::canUseSite();
    }

    public static function canCreate(): bool
    {
        return static::canUseSite();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canUseSite() && ($record->isEditable() || static::canApproveSite());
    }

    public static function canDelete(Model $record): bool
    {
        return $record->isEditable() && (static::isAdminPanel() || $record->submitted_by === auth()->id());
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canApproveSite()) {
            return null;
        }

        $count = static::getEloquentQuery()->where('status', 'submitted')->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Waiting for review';
    }

    public static function form(Form $form): Form
    {
        $today = now(config('app.business_timezone'))->toDateString();

        return $form
            ->schema([
                Forms\Components\Section::make('Day')
                    ->columns(3)
                    ->schema([
                        Forms\Components\Select::make('project_id')
                            ->label('Site / project')
                            ->relationship('project', 'title', fn (Builder $query) => static::siteProjectQuery($query))
                            ->rules([static::siteProjectRule()])
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => static::prefillManpower($get, $set)),
                        Forms\Components\DatePicker::make('date')
                            ->default($today)
                            ->maxDate($today)
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => static::prefillManpower($get, $set))
                            ->helperText(fn (?string $state): ?string => $state ? 'B.S. '.MusterRoll::bsDate($state) : null)
                            ->rules([
                                fn (Get $get, ?SiteReport $record): Closure => function (string $attribute, $value, Closure $fail) use ($get, $record): void {
                                    $exists = SiteReport::query()
                                        ->where('project_id', $get('project_id'))
                                        ->whereDate('date', Carbon::parse($value)->toDateString())
                                        ->when($record, fn (Builder $query) => $query->whereKeyNot($record->getKey()))
                                        ->exists();

                                    if ($exists) {
                                        $fail('This site already has a report for that day. Open it and add to it instead.');
                                    }
                                },
                            ]),
                        Forms\Components\Select::make('weather')
                            ->options(SiteReport::WEATHER)
                            ->default('sunny'),
                    ]),
                Forms\Components\Section::make('Manpower on site')
                    ->description('Filled from today\'s labour attendance. Add others (e.g. subcontractor crew) by hand.')
                    ->collapsible()
                    ->schema([
                        Forms\Components\Repeater::make('manpower')
                            ->hiddenLabel()
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('Add trade')
                            ->reorderable(false)
                            ->grid(['default' => 1, 'md' => 2, 'xl' => 3])
                            ->schema([
                                Forms\Components\TextInput::make('trade')
                                    ->datalist(array_values(Labourer::WORK_TYPES))
                                    ->required(),
                                Forms\Components\TextInput::make('count')
                                    ->numeric()
                                    ->integer()
                                    ->minValue(0)
                                    ->required(),
                            ])
                            ->hintAction(Forms\Components\Actions\Action::make('fromAttendance')
                                ->label('Refill from attendance')
                                ->icon('heroicon-m-arrow-path')
                                ->action(fn (Get $get, Set $set) => $set('manpower', SiteReport::manpowerFromAttendance($get('project_id'), $get('date'))))),
                    ]),
                Forms\Components\Section::make('Work')
                    ->schema([
                        Forms\Components\Textarea::make('work_done')
                            ->label('Work done today')
                            ->placeholder("e.g. Ground floor column casting completed (C1–C12).\nBrick work on first floor east wall.")
                            ->rows(4)
                            ->required(),
                        Forms\Components\Repeater::make('work_items')
                            ->label('Quantities / task progress')
                            ->defaultItems(0)
                            ->addActionLabel('Add item')
                            ->collapsible()
                            ->columns(['default' => 2, 'md' => 6])
                            ->schema([
                                Forms\Components\TextInput::make('description')
                                    ->placeholder('e.g. Slab concreting')
                                    ->required()
                                    ->columnSpan(['default' => 2, 'md' => 2]),
                                Forms\Components\TextInput::make('quantity')
                                    ->numeric(),
                                Forms\Components\TextInput::make('unit')
                                    ->datalist(['m³', 'm²', 'm', 'cft', 'sq.ft', 'rft', 'kg', 'nos', 'bags']),
                                Forms\Components\Select::make('task_id')
                                    ->label('Project task')
                                    ->options(fn (Get $get): array => Task::query()
                                        ->where('project_id', $get('../../project_id'))
                                        ->where('status', '!=', 'completed')
                                        ->orderBy('title')
                                        ->pluck('title', 'id')
                                        ->all())
                                    ->searchable()
                                    ->live(),
                                Forms\Components\Toggle::make('task_completed')
                                    ->label('Task finished')
                                    ->helperText('Marked complete when approved.')
                                    ->visible(fn (Get $get): bool => filled($get('task_id')))
                                    ->inline(false),
                            ]),
                        Forms\Components\FileUpload::make('photos')
                            ->label('Site photos')
                            ->image()
                            ->multiple()
                            ->reorderable()
                            ->appendFiles()
                            ->maxFiles(15)
                            ->maxSize(8192)
                            ->imageResizeMode('contain')
                            ->imageResizeTargetWidth('1600')
                            ->imageResizeTargetHeight('1600')
                            ->disk('public')
                            ->directory('site-reports')
                            ->panelLayout('grid')
                            ->helperText('Photos are shown to the client once the report is approved.'),
                    ]),
                Forms\Components\Section::make('Notes')
                    ->columns(['default' => 1, 'md' => 3])
                    ->collapsible()
                    ->schema([
                        Forms\Components\Textarea::make('issues')
                            ->label('Problems / delays')
                            ->placeholder('Material shortage, rain, waiting for client decision…')
                            ->rows(3),
                        Forms\Components\Textarea::make('next_day_plan')
                            ->label('Plan for tomorrow')
                            ->rows(3),
                        Forms\Components\Textarea::make('visitors')
                            ->label('Visitors / instructions received')
                            ->rows(3),
                    ]),
            ]);
    }

    protected static function prefillManpower(Get $get, Set $set): void
    {
        if (blank($get('manpower'))) {
            $set('manpower', SiteReport::manpowerFromAttendance($get('project_id'), $get('date')));
        }
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['project', 'submitter']))
            ->columns([
                Tables\Columns\TextColumn::make('date')
                    ->date('D, M j, Y')
                    ->description(fn (SiteReport $record): string => 'B.S. '.MusterRoll::bsDate($record->date))
                    ->sortable(),
                Tables\Columns\TextColumn::make('project.title')
                    ->label('Site')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('work_done')
                    ->label('Work done')
                    ->limit(70)
                    ->wrap(),
                Tables\Columns\TextColumn::make('manpower_total')
                    ->label('Manpower')
                    ->state(fn (SiteReport $record): int => $record->totalManpower())
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('photos_count')
                    ->label('Photos')
                    ->state(fn (SiteReport $record): int => count($record->photos ?? []))
                    ->icon('heroicon-o-photo'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => SiteReport::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => MusterRollResource::statusColor($state))
                    ->tooltip(fn (SiteReport $record): ?string => $record->review_note),
                Tables\Columns\TextColumn::make('submitter.name')
                    ->label('By')
                    ->toggleable(),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('project_id')
                    ->label('Site')
                    ->relationship('project', 'title', fn (Builder $query) => static::siteProjectQuery($query)),
                Tables\Filters\SelectFilter::make('status')
                    ->options(SiteReport::STATUSES),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->columns(4)
            ->schema([
                Infolists\Components\TextEntry::make('project.title')->label('Site'),
                Infolists\Components\TextEntry::make('date')
                    ->date('l, M j, Y')
                    ->helperText(fn (SiteReport $record): string => 'B.S. '.MusterRoll::bsDate($record->date)),
                Infolists\Components\TextEntry::make('weather')
                    ->formatStateUsing(fn (?string $state): ?string => SiteReport::WEATHER[$state] ?? $state)
                    ->placeholder('—'),
                Infolists\Components\TextEntry::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => SiteReport::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => MusterRollResource::statusColor($state))
                    ->helperText(fn (SiteReport $record): ?string => $record->submitter ? "By {$record->submitter->name}" : null),
                Infolists\Components\TextEntry::make('review_note')
                    ->label('Reviewer\'s note')
                    ->visible(fn (SiteReport $record): bool => filled($record->review_note))
                    ->color(fn (SiteReport $record): string => $record->status === 'returned' ? 'danger' : 'gray')
                    ->columnSpanFull(),
                ...static::reportEntries(),
            ]);
    }

    /**
     * The report body, shared with the client portal.
     *
     * @return array<Infolists\Components\Component>
     */
    public static function reportEntries(): array
    {
        return [
            Infolists\Components\Section::make('Work done')
                ->columnSpanFull()
                ->schema([
                    Infolists\Components\TextEntry::make('work_done')
                        ->hiddenLabel()
                        ->formatStateUsing(fn (?string $state): string => nl2br(e($state)))
                        ->html(),
                    Infolists\Components\RepeatableEntry::make('work_items')
                        ->label('Quantities')
                        ->visible(fn (SiteReport $record): bool => filled($record->work_items))
                        ->columns(4)
                        ->schema([
                            Infolists\Components\TextEntry::make('description')->label('Item'),
                            Infolists\Components\TextEntry::make('quantity')->placeholder('—'),
                            Infolists\Components\TextEntry::make('unit')->placeholder('—'),
                            Infolists\Components\TextEntry::make('task_completed')
                                ->label('Task')
                                ->formatStateUsing(fn ($state): string => $state ? 'Finished' : '—'),
                        ]),
                ]),
            Infolists\Components\Section::make('Photos')
                ->columnSpanFull()
                ->visible(fn (SiteReport $record): bool => filled($record->photos))
                ->schema([
                    Infolists\Components\ViewEntry::make('photos')
                        ->hiddenLabel()
                        ->view('filament.infolists.site-report-photos'),
                ]),
            Infolists\Components\Section::make()
                ->columnSpanFull()
                ->columns(['default' => 1, 'md' => 4])
                ->schema([
                    Infolists\Components\TextEntry::make('manpower')
                        ->label('Manpower')
                        ->state(fn (SiteReport $record): array => collect($record->manpower ?? [])->map(fn (array $row): string => "{$row['trade']}: {$row['count']}")->all())
                        ->listWithLineBreaks()
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('issues')->label('Problems / delays')->placeholder('None'),
                    Infolists\Components\TextEntry::make('next_day_plan')->label('Plan for tomorrow')->placeholder('—'),
                    Infolists\Components\TextEntry::make('visitors')->label('Visitors / instructions')->placeholder('—'),
                ]),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scopeToSites(parent::getEloquentQuery());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSiteReports::route('/'),
            'create' => Pages\CreateSiteReport::route('/create'),
            'view' => Pages\ViewSiteReport::route('/{record}'),
            'edit' => Pages\EditSiteReport::route('/{record}/edit'),
        ];
    }
}
