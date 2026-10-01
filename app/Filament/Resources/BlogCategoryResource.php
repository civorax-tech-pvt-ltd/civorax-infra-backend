<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BlogCategoryResource\Pages;
use App\Filament\Resources\Concerns\ShieldPermissions;
use App\Models\BlogCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Blog categories (website /blog/category/{slug}).
 */
class BlogCategoryResource extends Resource
{
    use ShieldPermissions;

    protected static ?string $model = BlogCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Website';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Blog categories';

    protected static ?string $modelLabel = 'blog category';

    protected static string $shieldPermission = 'blog::category';

    public static function canDelete(Model $record): bool
    {
        return static::shieldAllows('delete') && ! $record->posts()->withTrashed()->exists();
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(100)
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set, ?string $state, string $operation): void {
                    if ($operation === 'create') {
                        $set('slug', Str::slug((string) $state));
                    }
                }),
            Forms\Components\TextInput::make('slug')
                ->label('URL slug')
                ->required()
                ->alphaDash()
                ->maxLength(100)
                ->unique(BlogCategory::class, 'slug', ignoreRecord: true)
                ->helperText('Page address: /blog/category/{slug}.'),
            Forms\Components\Textarea::make('description')->rows(2)->maxLength(500)->columnSpanFull(),
            Forms\Components\TextInput::make('seo_title')->label('SEO title')->maxLength(70),
            Forms\Components\TextInput::make('seo_description')->label('SEO description')->maxLength(320),
            Forms\Components\Toggle::make('is_visible')->label('Shown on the website')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort')
            ->defaultSort('sort')
            ->columns([
                Tables\Columns\TextColumn::make('name')->weight('bold')->description(fn (BlogCategory $record): string => "/blog/category/{$record->slug}"),
                Tables\Columns\TextColumn::make('posts_count')->label('Posts')->counts('posts'),
                Tables\Columns\IconColumn::make('is_visible')->label('Visible')->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageBlogCategories::route('/'),
        ];
    }
}
