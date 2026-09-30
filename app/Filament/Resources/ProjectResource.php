<?php

namespace App\Filament\Resources;

use App\Filament\Forms\LocationFields;
use App\Filament\Resources\Concerns\ScopesToTeamMember;
use App\Filament\Resources\ProjectResource\Pages;
use App\Filament\Resources\ProjectResource\RelationManagers;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\TeamMember;
use App\Notifications\Alerts;
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

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

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
                LocationFields::make(
                    defaultRadius: 1000,
                    description: 'Team members assigned to this project are marked present when they open the team app within this distance of the site.',
                )->columnSpanFull(),
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
                    ->saveRelationshipsUsing(fn (Project $record, $state) => Alerts::addedToProject($record, static::syncTeamMembers($record->teamMembers(), $state)))
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->helperText('Assigned staff see this project in the team panel.'),
                Forms\Components\TextInput::make('fee')
                    ->label('Contract fee')
                    ->numeric()
                    ->prefix('NPR')
                    ->minValue(fn (?Project $record): float => $record?->exists ? $record->amountPaid() : 0)
                    ->validationMessages(['min' => 'The fee cannot be less than what has already been paid (NPR :min).'])
                    ->required(fn (Get $get): bool => ! in_array($get('status'), ['inquiry', 'planning', 'on_hold'], true))
                    ->formatStateUsing(fn ($state, ?Project $record) => $record?->acceptedQuotation()?->total ?? $state)
                    ->disabled(fn (?Project $record): bool => $record?->acceptedQuotation() !== null)
                    ->helperText(fn (?Project $record): string => ($accepted = $record?->acceptedQuotation())
                        ? "Set by accepted {$accepted->label()}. To change the price, create and accept a new quotation."
                        : 'Leave empty until the price is agreed. Accepting a quotation fills it in.'),
                Forms\Components\Placeholder::make('balance')
                    ->label('Paid / balance due')
                    ->content(fn (Project $record): string => $record->fee === null
                        ? 'NPR '.number_format($record->amountPaid(), 2).' paid · fee not set'
                        : 'NPR '.number_format($record->amountPaid(), 2).' paid · NPR '.number_format($record->balanceDue(), 2).' due')
                    ->visibleOn('edit'),
                Forms\Components\Select::make('client_type')
                    ->options(Project::CLIENT_TYPES)
                    ->placeholder('Not set')
                    ->helperText('Used for future VAT reporting.'),
                Forms\Components\Select::make('price_basis')
                    ->label('Contract price')
                    ->options(Project::PRICE_BASES)
                    ->default('vat_inclusive')
                    ->required()
                    ->selectablePlaceholder(false)
                    ->helperText('While the company is PAN-only the contract value is the final price the client pays.'),
                Forms\Components\Toggle::make('track_item_costs')
                    ->label('Track cost per BOQ item')
                    ->helperText('For bigger jobs: lets site entries be tagged to BOQ items and shows cost vs earned value per item.')
                    ->inline(false)
                    ->visible(fn (): bool => static::canViewCosts()),
                Forms\Components\DatePicker::make('start_date')
                    ->default(now()),
                Forms\Components\DatePicker::make('estimated_end_date')
                    ->afterOrEqual('start_date'),
                Forms\Components\Hidden::make('created_by')
                    ->default(fn () => auth()->id()),
            ]);
    }

    /**
     * Cost budgets, profit and cash: super admins and roles given "view_project_costs" (Approval Settings).
     */
    public static function canViewCosts(): bool
    {
        return (bool) auth()->user()?->hasSitePower('view_project_costs');
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
                Tables\Actions\Action::make('costs')
                    ->label('Costs')
                    ->icon('heroicon-o-chart-pie')
                    ->color('gray')
                    ->visible(fn (): bool => static::canViewCosts())
                    ->url(fn (Project $record): string => static::getUrl('costs', ['record' => $record])),
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
            RelationManagers\BoqItemsRelationManager::class,
            RelationManagers\QuotationsRelationManager::class,
            RelationManagers\PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProjects::route('/'),
            'create' => Pages\CreateProject::route('/create'),
            'edit' => Pages\EditProject::route('/{record}/edit'),
            'costs' => Pages\ProjectCosts::route('/{record}/costs'),
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
