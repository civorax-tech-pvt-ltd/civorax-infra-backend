<?php

namespace App\Filament\Resources;

use App\Filament\Forms\LocationFields;
use App\Filament\Resources\Concerns\SiteAccess;
use App\Filament\Resources\ProfessionalResource\Pages;
use App\Models\OfficeLocation;
use App\Models\Professional;
use App\Models\Project;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Directory of professionals (engineers, architects, masons, electricians…) with location, for finding
 * the right person near a site. Team members can add people; admins manage everything.
 */
class ProfessionalResource extends Resource
{
    use SiteAccess;

    protected static ?string $model = Professional::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-circle';

    protected static ?string $navigationGroup = 'CRM';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Professionals';

    protected static ?string $recordTitleAttribute = 'fullname';

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
        return static::isAdminPanel() || (int) $record->added_by === (int) auth()->id();
    }

    public static function canDelete(Model $record): bool
    {
        return static::isAdminPanel();
    }

    /**
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['fullname', 'contact', 'address'];
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Profile')
                    ->columns(3)
                    ->schema([
                        Forms\Components\FileUpload::make('photo_path')
                            ->label('Photo (optional)')
                            ->image()
                            ->avatar()
                            ->imageEditor()
                            ->imageCropAspectRatio('1:1')
                            ->imageResizeTargetWidth('500')
                            ->imageResizeTargetHeight('500')
                            ->disk('public')
                            ->directory('professionals')
                            ->maxSize(4096),
                        Forms\Components\TextInput::make('fullname')
                            ->label('Full name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Select::make('professional_type')
                            ->label('Profession')
                            ->options(Professional::TYPES)
                            ->searchable()
                            ->required(),
                        Forms\Components\TextInput::make('years_of_experience')
                            ->label('Years of experience')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(70)
                            ->suffix('years'),
                        Forms\Components\Toggle::make('is_available')
                            ->label('Available for work')
                            ->default(true)
                            ->inline(false),
                    ]),
                Forms\Components\Section::make('Contact')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('contact')
                            ->label('Contact no.')
                            ->tel()
                            ->required()
                            ->maxLength(20)
                            ->unique(Professional::class, 'contact', ignoreRecord: true)
                            ->validationMessages(['unique' => 'Someone with this number is already in the directory.']),
                        Forms\Components\TextInput::make('alt_contact')
                            ->label('Other number')
                            ->tel()
                            ->maxLength(20),
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('address')
                            ->required()
                            ->placeholder('e.g. Itahari-4, Sunsari')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),
                LocationFields::make(
                    defaultRadius: 0,
                    description: 'Optional. Lets you find people near a site and open their place in Google Maps.',
                    title: 'Location (optional)',
                    withRadius: false,
                ),
                Forms\Components\Textarea::make('notes')
                    ->label('Notes (skills, rates, past work)')
                    ->rows(3)
                    ->columnSpanFull(),
                Forms\Components\Hidden::make('added_by')
                    ->default(fn () => auth()->id()),
            ]);
    }

    /**
     * Places to measure distance from: active projects with a location, then offices.
     *
     * @return array<string, string>
     */
    protected static function nearOptions(): array
    {
        $projects = Project::query()->whereNotNull('latitude')->whereNotNull('longitude')->whereNotIn('status', ['completed'])->orderBy('title')->pluck('title', 'id')
            ->mapWithKeys(fn (string $title, int $id): array => ["project:{$id}" => "Site: {$title}"]);
        $offices = OfficeLocation::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, int $id): array => ["office:{$id}" => "Office: {$name}"]);

        return $projects->merge($offices)->all();
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    protected static function nearPoint(?string $value): ?array
    {
        [$kind, $id] = array_pad(explode(':', (string) $value, 2), 2, null);
        $place = match ($kind) {
            'project' => Project::find($id),
            'office' => OfficeLocation::find($id),
            default => null,
        };

        return $place && $place->latitude !== null ? [(float) $place->latitude, (float) $place->longitude] : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            // Filament wraps filter queries in a nested WHERE, which drops ORDER BY; so sort by distance here.
            ->modifyQueryUsing(function (Builder $query, $livewire): Builder {
                $point = static::nearPoint($livewire->tableFilters['near']['value'] ?? null);

                return $point ? $query->with('addedBy')->nearest(...$point) : $query->with('addedBy');
            })
            ->columns([
                Tables\Columns\ImageColumn::make('photo_path')
                    ->label('')
                    ->disk('public')
                    ->circular(),
                Tables\Columns\TextColumn::make('fullname')
                    ->label('Name')
                    ->searchable(['fullname', 'address', 'notes'])
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Professional $record): string => $record->typeLabel()),
                Tables\Columns\TextColumn::make('contact')
                    ->searchable(['contact', 'alt_contact'])
                    ->icon('heroicon-o-phone')
                    ->url(fn (Professional $record): string => 'tel:'.preg_replace('/[^0-9+]/', '', $record->contact))
                    ->description(fn (Professional $record): ?string => $record->alt_contact),
                Tables\Columns\TextColumn::make('years_of_experience')
                    ->label('Experience')
                    ->suffix(' yrs')
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('address')
                    ->wrap()
                    ->limit(40),
                Tables\Columns\TextColumn::make('distance')
                    ->label('Distance')
                    ->state(function (Professional $record, $livewire): ?string {
                        $point = static::nearPoint($livewire->tableFilters['near']['value'] ?? null);
                        $km = $point ? $record->distanceFrom(...$point) : null;

                        return $km === null ? null : "{$km} km";
                    })
                    ->placeholder('—')
                    ->visible(fn ($livewire): bool => filled($livewire->tableFilters['near']['value'] ?? null)),
                Tables\Columns\TextColumn::make('map')
                    ->label('Map')
                    ->state(fn (Professional $record): ?string => $record->hasLocation() ? 'Open' : null)
                    ->icon('heroicon-o-map-pin')
                    ->color('primary')
                    ->url(fn (Professional $record): ?string => $record->mapUrl(), shouldOpenInNewTab: true)
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('is_available')
                    ->label('Available')
                    ->boolean(),
                Tables\Columns\TextColumn::make('addedBy.name')
                    ->label('Added by')
                    ->description(fn (Professional $record): string => $record->created_at->format('M j, Y'))
                    ->toggleable(),
            ])
            ->defaultSort('fullname')
            ->filters([
                Tables\Filters\SelectFilter::make('professional_type')
                    ->label('Profession')
                    ->options(Professional::TYPES)
                    ->multiple(),
                Tables\Filters\TernaryFilter::make('is_available')
                    ->label('Available'),
                Tables\Filters\SelectFilter::make('near')
                    ->label('Near a site / office')
                    ->options(fn (): array => static::nearOptions())
                    ->searchable()
                    ->query(fn (Builder $query): Builder => $query), // sorting only (see modifyQueryUsing)
                Tables\Filters\TrashedFilter::make()
                    ->visible(fn (): bool => static::isAdminPanel()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\RestoreAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProfessionals::route('/'),
            'create' => Pages\CreateProfessional::route('/create'),
            'edit' => Pages\EditProfessional::route('/{record}/edit'),
        ];
    }
}
