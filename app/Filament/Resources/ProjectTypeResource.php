<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProjectTypeResource\Pages;
use App\Models\Project;
use App\Models\ProjectType;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\HtmlString;

class ProjectTypeResource extends Resource
{
    protected static ?string $model = ProjectType::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationGroup = 'Projects';

    protected static ?int $navigationSort = 6;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('slug')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Textarea::make('description')
                    ->columnSpanFull(),
                Forms\Components\Toggle::make('is_active')
                    ->required(),
                Forms\Components\Section::make('Milestone template')
                    ->description('The stages of the work, in order. Every new project of this type gets these milestones and tasks automatically (you can still change them per project). Drag to reorder.')
                    ->schema([
                        Forms\Components\Placeholder::make('billing_total')
                            ->hiddenLabel()
                            ->content(function (Forms\Get $get): HtmlString {
                                $total = collect($get('milestoneTemplates') ?? [])->sum(fn (array $item): float => (float) ($item['billing_percent'] ?? 0));
                                $color = match (true) {
                                    $total > 100 => '#dc2626',
                                    $total == 100 || $total == 0 => '#16a34a',
                                    default => '#d97706',
                                };
                                $hint = match (true) {
                                    $total == 0 => 'No billing set: the client is billed outside milestones.',
                                    $total > 100 => 'Over 100%: reduce some milestones.',
                                    $total < 100 => 'Usually the milestones add up to 100% of the fee.',
                                    default => 'The whole fee is covered.',
                                };

                                return new HtmlString('<span style="color:'.$color.';font-weight:600">Billing total: '.rtrim(rtrim(number_format($total, 2), '0'), '.').'%</span> · '.e($hint));
                            }),
                        Forms\Components\Repeater::make('milestoneTemplates')
                            ->hiddenLabel()
                            ->relationship()
                            ->orderColumn('sequence')
                            ->collapsible()
                            ->collapsed(fn (string $operation): bool => $operation === 'edit')
                            ->itemLabel(fn (array $state): ?string => filled($state['title'] ?? null)
                                ? $state['title'].(filled($state['billing_percent'] ?? null) ? " · {$state['billing_percent']}%" : '')
                                : null)
                            ->addActionLabel('Add milestone')
                            ->live()
                            ->rules([fn (): Closure => function (string $attribute, $value, Closure $fail): void {
                                if (collect($value ?? [])->sum(fn (array $item): float => (float) ($item['billing_percent'] ?? 0)) > 100) {
                                    $fail('The milestones\' billing adds up to more than 100% of the fee.');
                                }
                            }])
                            ->columns(3)
                            ->schema([
                                Forms\Components\TextInput::make('title')
                                    ->label('Milestone name')
                                    ->placeholder('e.g. Foundation work')
                                    ->helperText('A stage of the work the client will recognise.')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\Select::make('phase')
                                    ->helperText('While this milestone is the current one, the project shows this status.')
                                    ->options(Project::PHASES),
                                Forms\Components\TextInput::make('billing_percent')
                                    ->label('Billing (% of fee)')
                                    ->placeholder('e.g. 20')
                                    ->helperText('Becomes due from the client when this milestone is completed. Leave empty if none.')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->live(onBlur: true)
                                    ->suffix('%'),
                                Forms\Components\Repeater::make('tasks')
                                    ->relationship()
                                    ->orderColumn('sort')
                                    ->addActionLabel('Add task')
                                    ->defaultItems(0)
                                    ->columns(4)
                                    ->columnSpanFull()
                                    ->schema([
                                        Forms\Components\TextInput::make('title')
                                            ->label('Task')
                                            ->placeholder('e.g. Excavation and PCC')
                                            ->required()
                                            ->maxLength(255)
                                            ->columnSpan(3),
                                        Forms\Components\TextInput::make('weight')
                                            ->helperText('1 = small, 10 = big job. Bigger tasks move progress more.')
                                            ->numeric()
                                            ->integer()
                                            ->minValue(1)
                                            ->maxValue(10)
                                            ->default(1)
                                            ->required(),
                                    ]),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('slug')
                    ->searchable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
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
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProjectTypes::route('/'),
            'create' => Pages\CreateProjectType::route('/create'),
            'edit' => Pages\EditProjectType::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
