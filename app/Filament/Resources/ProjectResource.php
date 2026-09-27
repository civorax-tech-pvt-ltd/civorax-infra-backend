<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Concerns\ScopesToTeamMember;
use App\Filament\Resources\ProjectResource\Pages;
use App\Filament\Resources\ProjectResource\RelationManagers;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\TeamMember;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProjectResource extends Resource
{
    use ScopesToTeamMember;

    protected static ?string $model = Project::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Projects';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('client_id')
                    ->relationship('client', 'contact_person')
                    ->getOptionLabelFromRecordUsing(fn (Client $record): string => $record->selectLabel())
                    ->searchable(Client::SEARCH_COLUMNS)
                    ->searchPrompt('Search by name, phone, company or address')
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => static::suggestTitle($get, $set)),
                Forms\Components\Select::make('project_type_id')
                    ->relationship('projectType', 'name')
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => static::suggestTitle($get, $set)),
                Forms\Components\TextInput::make('title')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('e.g. Interior Design – Bipul Tharu Residence, Butwal')
                    ->helperText('Filled in from the project type and client. Edit freely.'),
                Forms\Components\Hidden::make('suggested_title')
                    ->dehydrated(false),
                Forms\Components\RichEditor::make('description')
                    ->label('Scope of work')
                    ->required()
                    ->disableToolbarButtons(['attachFiles', 'codeBlock'])
                    ->placeholder('What the client wants: plot size, floors, rooms, style, budget, special requirements, deliverables…')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('site_address')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('city')
                    ->maxLength(255),
                Forms\Components\TextInput::make('ward_no')
                    ->maxLength(255),
                Forms\Components\Select::make('status')
                    ->options(Project::STATUSES)
                    ->required()
                    ->default('inquiry')
                    ->live()
                    ->helperText('Inquiry and On Hold stay as set. Otherwise the status follows the current milestone\'s phase.'),
                Forms\Components\Placeholder::make('progress')
                    ->label('Overall progress')
                    ->content(fn (Project $record) => view('filament.components.progress-bar', ['percent' => $record->progress]))
                    ->visibleOn('edit'),
                Forms\Components\Select::make('teamMembers')
                    ->label('Team')
                    ->relationship('teamMembers', 'fullname', fn (Builder $query) => static::limitTeamMemberOptions($query))
                    ->saveRelationshipsUsing(fn (Project $record, $state) => static::syncTeamMembers($record->teamMembers(), $state))
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->helperText('Assigned staff see this project in the team panel.'),
                Forms\Components\TextInput::make('fee')
                    ->label('Contract fee')
                    ->numeric()
                    ->minValue(0)
                    ->prefix('NPR')
                    ->required(fn (Get $get): bool => ! in_array($get('status'), ['inquiry', 'planning', 'on_hold'], true))
                    ->helperText('Leave empty until the price is agreed. Accepting a quotation fills it in.'),
                Forms\Components\Placeholder::make('balance')
                    ->label('Paid / balance due')
                    ->content(fn (Project $record): string => $record->fee === null
                        ? 'NPR '.number_format($record->amountPaid(), 2).' paid · fee not set'
                        : 'NPR '.number_format($record->amountPaid(), 2).' paid · NPR '.number_format($record->balanceDue(), 2).' due')
                    ->visibleOn('edit'),
                Forms\Components\DatePicker::make('start_date')
                    ->default(now()),
                Forms\Components\DatePicker::make('estimated_end_date')
                    ->afterOrEqual('start_date'),
                Forms\Components\Hidden::make('created_by')
                    ->default(fn () => auth()->id()),
            ]);
    }

    /**
     * Suggest "<Type> – <Client>" while the title is empty or still holds an earlier suggestion.
     */
    protected static function suggestTitle(Get $get, Set $set): void
    {
        $type = ProjectType::query()->whereKey($get('project_type_id'))->value('name');
        $client = Client::query()->whereKey($get('client_id'))->value('contact_person');

        if ($type === null || $client === null) {
            return;
        }

        $current = (string) $get('title');

        if ($current !== '' && $current !== $get('suggested_title')) {
            return;
        }

        $suggestion = "{$type} – {$client}";

        $set('title', $suggestion);
        $set('suggested_title', $suggestion);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('client.contact_person')
                    ->label('Client')
                    ->description(fn (Project $record): ?string => $record->client?->contact)
                    ->searchable(['contact_person', 'contact'])
                    ->sortable(),
                Tables\Columns\TextColumn::make('projectType.name')
                    ->label('Type')
                    ->sortable(),
                Tables\Columns\TextColumn::make('title')
                    ->searchable(),
                Tables\Columns\TextColumn::make('site_address')
                    ->searchable(),
                Tables\Columns\TextColumn::make('city')
                    ->searchable(),
                Tables\Columns\TextColumn::make('ward_no')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Project::STATUSES[$state] ?? $state)
                    ->searchable(),
                Tables\Columns\ViewColumn::make('progress')
                    ->view('filament.components.progress-bar')
                    ->sortable(),
                Tables\Columns\TextColumn::make('fee')
                    ->money('NPR')
                    ->placeholder('Not set')
                    ->sortable(),
                Tables\Columns\TextColumn::make('estimated_end_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\MilestonesRelationManager::class,
            RelationManagers\TasksRelationManager::class,
            RelationManagers\QuotationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProjects::route('/'),
            'create' => Pages\CreateProject::route('/create'),
            'edit' => Pages\EditProject::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scopeToTeamMember(
            parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]),
            fn (Builder $query, TeamMember $teamMember) => $query->visibleToTeamMember($teamMember),
        );
    }
}
