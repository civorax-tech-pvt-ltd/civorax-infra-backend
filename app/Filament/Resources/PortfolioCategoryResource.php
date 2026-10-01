<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Concerns\ShieldPermissions;
use App\Filament\Resources\PortfolioCategoryResource\Pages;
use App\Models\PortfolioCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Categories of the website's "Our Work" (filters and landing pages such as /our-work/home-concepts).
 */
class PortfolioCategoryResource extends Resource
{
    use ShieldPermissions;

    protected static ?string $model = PortfolioCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Website';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Portfolio Categories';

    protected static string $shieldPermission = 'portfolio::category';

    public static function canDelete(Model $record): bool
    {
        return static::shieldAllows('delete') && ! $record->projects()->exists();
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(100)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Set $set, ?string $state, ?PortfolioCategory $record) => $record ? null : $set('slug', Str::slug((string) $state))),
            Forms\Components\TextInput::make('slug')
                ->label('URL slug')
                ->prefix('/our-work/')
                ->required()
                ->alphaDash()
                ->maxLength(100)
                ->unique(PortfolioCategory::class, 'slug', ignoreRecord: true)
                ->helperText('Changing it changes the page address. Avoid once search engines have indexed it.'),
            Forms\Components\Textarea::make('description')
                ->rows(2)
                ->helperText('Shown under the heading on the category page.')
                ->columnSpanFull(),
            Forms\Components\TextInput::make('seo_title')
                ->label('SEO title')
                ->maxLength(70)
                ->placeholder(fn ($get): string => ($get('name') ?: 'Category').' House Designs in Nepal')
                ->helperText('Shown in Google results. Best under 60 characters.'),
            Forms\Components\TextInput::make('seo_description')
                ->label('SEO description')
                ->maxLength(160)
                ->helperText('Best 120–160 characters. Falls back to the description.'),
            Forms\Components\Toggle::make('is_visible')
                ->label('Show on website')
                ->default(true)
                ->inline(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort')
            ->defaultSort('sort')
            ->columns([
                Tables\Columns\TextColumn::make('name')->weight('bold'),
                Tables\Columns\TextColumn::make('slug')->prefix('/our-work/')->color('gray'),
                Tables\Columns\TextColumn::make('projects_count')->label('Projects')->counts('projects')->badge(),
                Tables\Columns\IconColumn::make('is_visible')->label('Visible')->boolean(),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('View on website')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (PortfolioCategory $record): string => rtrim(config('services.website.url'), '/')."/en/our-work/{$record->slug}", shouldOpenInNewTab: true),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManagePortfolioCategories::route('/')];
    }
}
