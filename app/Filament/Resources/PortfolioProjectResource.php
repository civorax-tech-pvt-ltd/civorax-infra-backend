<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Concerns\ShieldPermissions;
use App\Filament\Resources\PortfolioProjectResource\Pages;
use App\Models\PortfolioProject;
use App\Models\Project;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Projects shown on the website's "Our Work", with their SEO. Saving tells the website to refresh.
 */
class PortfolioProjectResource extends Resource
{
    use ShieldPermissions;

    protected static ?string $model = PortfolioProject::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationGroup = 'Website';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Portfolio (Our Work)';

    protected static ?string $modelLabel = 'portfolio project';

    protected static ?string $recordTitleAttribute = 'title';

    protected static string $shieldPermission = 'portfolio::project';

    /**
     * Super admins and roles given "Our Work (portfolio)" in Approval Settings; others submit for review.
     */
    public static function canPublish(): bool
    {
        return PortfolioProject::canPublish(auth()->user());
    }

    public static function canEdit(Model $record): bool
    {
        return static::shieldAllows('update') && $record->isEditableBy(auth()->user());
    }

    public static function canDelete(Model $record): bool
    {
        return static::shieldAllows('delete') && (static::canPublish() || $record->isEditableBy(auth()->user()) && ! $record->is_published);
    }

    public static function canRestore(Model $record): bool
    {
        return static::shieldAllows('restore') && static::canPublish();
    }

    public static function canForceDelete(Model $record): bool
    {
        return static::shieldAllows('force_delete') && static::canPublish();
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny() || ! static::canPublish()) {
            return null;
        }

        $count = PortfolioProject::query()->where('review_status', 'pending')->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function websiteUrl(?PortfolioProject $project, string $locale = 'en'): ?string
    {
        return $project?->primaryCategory
            ? rtrim(config('services.website.url'), '/')."/{$locale}".$project->websitePath()
            : null;
    }

    /**
     * Upload field with an optional "or use an image link" alternative (for CDN or existing images).
     *
     * @return array<Forms\Components\Component>
     */
    protected static function imageFields(string $name, string $label, string $help, int $width): array
    {
        return [
            Forms\Components\FileUpload::make("{$name}_path")
                ->label($label)
                ->helperText($help)
                ->image()
                ->imageEditor()
                ->imageResizeMode('contain')
                ->imageResizeTargetWidth((string) $width)
                ->disk('public')
                ->directory('portfolio')
                ->maxSize(10240),
            Forms\Components\TextInput::make("{$name}_url")
                ->label('…or image link')
                ->url()
                ->maxLength(500)
                ->placeholder('https://…')
                ->helperText('Used only when no file is uploaded.'),
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Placeholder::make('review_feedback')
                ->hiddenLabel()
                ->columnSpanFull()
                ->visible(fn (?PortfolioProject $record): bool => $record?->review_status === 'changes_requested' && filled($record->review_note))
                ->content(fn (PortfolioProject $record): HtmlString => new HtmlString(
                    '<div style="padding:12px 14px;border-radius:10px;background:rgb(245 158 11 / .12);border:1px solid rgb(245 158 11 / .4)">'
                    .'<strong>Changes requested'.($record->reviewer ? ' by '.e($record->reviewer->name) : '').':</strong> '
                    .nl2br(e($record->review_note)).'<br><small>Edit the project, then press “Submit for review” again.</small></div>'
                )),
            Forms\Components\Tabs::make()
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    Forms\Components\Tabs\Tab::make('Content')
                        ->icon('heroicon-o-document-text')
                        ->columns(2)
                        ->schema([
                            Forms\Components\TextInput::make('title')
                                ->required()
                                ->maxLength(150)
                                ->live(onBlur: true)
                                ->afterStateUpdated(function (Get $get, Set $set, ?string $state, ?PortfolioProject $record): void {
                                    if (! $record?->is_published) {
                                        $set('slug', Str::slug((string) $state));
                                    }
                                }),
                            Forms\Components\TextInput::make('slug')
                                ->label('URL slug')
                                ->required()
                                ->alphaDash()
                                ->maxLength(150)
                                ->unique(PortfolioProject::class, 'slug', ignoreRecord: true)
                                ->helperText('Part of the page address. Don\'t change it after publishing: links and Google rankings use it.'),
                            Forms\Components\Textarea::make('short_description')
                                ->label('Short description (card)')
                                ->required()
                                ->maxLength(300)
                                ->rows(2)
                                ->helperText('One or two sentences shown on the project card.')
                                ->columnSpanFull(),
                            Forms\Components\Textarea::make('overview')
                                ->label('Project overview')
                                ->required()
                                ->rows(6)
                                ->helperText('The main text of the project page. Leave a blank line between paragraphs.')
                                ->columnSpanFull(),
                            Forms\Components\Select::make('status')
                                ->options(PortfolioProject::STATUSES)
                                ->default('concept')
                                ->required()
                                ->selectablePlaceholder(false),
                            Forms\Components\TextInput::make('location')
                                ->placeholder('e.g. Itahari, Sunsari')
                                ->maxLength(150)
                                ->helperText('Town names help local search ("house design in Itahari").'),
                            Forms\Components\TextInput::make('year')
                                ->numeric()
                                ->integer()
                                ->minValue(2000)
                                ->maxValue(2100),
                            Forms\Components\TextInput::make('client_label')
                                ->label('Client (public wording)')
                                ->placeholder('e.g. Private Residence')
                                ->helperText('Shown publicly. Don\'t use a private client\'s name without permission.')
                                ->maxLength(150),
                            Forms\Components\TagsInput::make('services')
                                ->placeholder('Add a service and press Enter')
                                ->suggestions(['Architectural Design', 'Structural Design', 'Interior Design', '3D Visualization', 'Construction', 'Renovation', 'Supervision', 'Estimation & BOQ', 'Municipal Approval', 'Landscape Design'])
                                ->columnSpanFull(),
                            Forms\Components\Textarea::make('challenge')
                                ->rows(3)
                                ->helperText('Optional case-study section.'),
                            Forms\Components\Textarea::make('solution')
                                ->rows(3),
                        ]),
                    Forms\Components\Tabs\Tab::make('Categories')
                        ->icon('heroicon-o-tag')
                        ->columns(2)
                        ->schema([
                            Forms\Components\Select::make('primary_category_id')
                                ->label('Main category')
                                ->relationship('primaryCategory', 'name')
                                ->required()
                                ->preload()
                                ->createOptionForm(fn (Form $form) => PortfolioCategoryResource::form($form))
                                ->helperText('Decides the page address: /our-work/{category}/{slug}.'),
                            Forms\Components\Select::make('categories')
                                ->label('Also show under')
                                ->relationship('categories', 'name')
                                ->multiple()
                                ->preload()
                                // Saved after the record, so keep the main category in the set explicitly.
                                ->saveRelationshipsUsing(fn (PortfolioProject $record, ?array $state) => $record->categories()->sync(
                                    array_values(array_unique([...array_map('intval', $state ?? []), (int) $record->primary_category_id])),
                                ))
                                ->helperText('The main category is always included.'),
                            Forms\Components\Toggle::make('is_featured')
                                ->label('Featured (home page and top of Our Work)')
                                ->inline(false),
                            Forms\Components\ToggleButtons::make('size')
                                ->label('Card size on Our Work')
                                ->options(['large' => 'Large', 'small' => 'Small'])
                                ->default('small')
                                ->inline(),
                        ]),
                    Forms\Components\Tabs\Tab::make('Images & video')
                        ->icon('heroicon-o-photo')
                        ->columns(2)
                        ->schema([
                            ...static::imageFields('thumbnail', 'Card image', 'Used on cards. About 1000 px wide.', 1200),
                            ...static::imageFields('cover', 'Cover image', 'Big banner on the project page and the share image on Facebook/WhatsApp. About 1800 px wide.', 2000),
                            Forms\Components\Repeater::make('gallery')
                                ->label('Gallery')
                                ->columnSpanFull()
                                ->defaultItems(0)
                                ->reorderable()
                                ->collapsible()
                                ->grid(2)
                                ->addActionLabel('Add photo')
                                ->itemLabel(fn (array $state): ?string => $state['alt'] ?? null)
                                ->schema([
                                    Forms\Components\FileUpload::make('path')
                                        ->label('Photo')
                                        ->image()
                                        ->imageEditor()
                                        ->imageResizeMode('contain')
                                        ->imageResizeTargetWidth('2000')
                                        ->disk('public')
                                        ->directory('portfolio')
                                        ->maxSize(10240),
                                    Forms\Components\TextInput::make('url')
                                        ->label('…or image link')
                                        ->url()
                                        ->maxLength(500),
                                    Forms\Components\TextInput::make('alt')
                                        ->label('Describe the photo (alt text)')
                                        ->placeholder('e.g. Living room with Newari wooden window, evening light')
                                        ->helperText('Read by Google Images and screen readers.')
                                        ->maxLength(160),
                                ]),
                            Forms\Components\Repeater::make('videos')
                                ->columnSpanFull()
                                ->defaultItems(0)
                                ->addActionLabel('Add video')
                                ->columns(2)
                                ->schema([
                                    Forms\Components\TextInput::make('title')->maxLength(150)->placeholder('Project walkthrough'),
                                    Forms\Components\TextInput::make('url')
                                        ->label('YouTube or video link')
                                        ->url()
                                        ->required()
                                        ->maxLength(500),
                                ]),
                        ]),
                    Forms\Components\Tabs\Tab::make('SEO')
                        ->icon('heroicon-o-magnifying-glass')
                        ->schema([
                            Forms\Components\TextInput::make('seo_title')
                                ->label('SEO title')
                                ->maxLength(70)
                                ->live(debounce: 500)
                                ->placeholder(fn (Get $get): string => ($get('title') ?: 'Project').' | CivoraX Infra')
                                ->helperText(fn (?string $state): string => 'The blue headline in Google. '.mb_strlen((string) $state).'/60 characters. Empty = project title.'),
                            Forms\Components\Textarea::make('seo_description')
                                ->label('SEO description')
                                ->maxLength(320)
                                ->rows(3)
                                ->live(debounce: 500)
                                ->helperText(fn (?string $state): string => 'The grey text under it. '.mb_strlen((string) $state).'/160 characters. Empty = short description.'),
                            Forms\Components\TextInput::make('seo_keywords')
                                ->label('Keywords')
                                ->placeholder('house design Itahari, modern home Nepal, 3D elevation')
                                ->helperText('Comma separated. Mostly used by Bing.')
                                ->maxLength(255),
                            Forms\Components\Placeholder::make('google_preview')
                                ->label('Google preview')
                                ->content(fn (Get $get, ?PortfolioProject $record): HtmlString => new HtmlString(
                                    '<div style="max-width:600px;font-family:Arial,sans-serif;padding:12px 14px;border:1px solid rgb(0 0 0 / .1);border-radius:10px;background:#fff">'
                                    .'<div style="font-size:12px;color:#202124">civoraxinfra.com › our-work › '.e((string) ($record?->primaryCategory?->slug ?? 'category')).'</div>'
                                    .'<div style="font-size:19px;color:#1a0dab;margin:2px 0;line-height:1.3">'.e(Str::limit((string) ($get('seo_title') ?: (($get('title') ?: 'Project title').' | CivoraX Infra')), 60)).'</div>'
                                    .'<div style="font-size:13px;color:#4d5156;line-height:1.5">'.e(Str::limit((string) ($get('seo_description') ?: $get('short_description') ?: 'Description…'), 160)).'</div>'
                                    .'</div>'
                                )),
                        ]),
                    Forms\Components\Tabs\Tab::make('Publishing')
                        ->icon('heroicon-o-globe-alt')
                        ->columns(2)
                        ->schema([
                            Forms\Components\Toggle::make('is_published')
                                ->label('Published on the website')
                                ->helperText('Off = draft, invisible to the public and to Google.')
                                ->visible(fn (): bool => static::canPublish())
                                ->inline(false),
                            Forms\Components\DateTimePicker::make('published_at')
                                ->label('Publish date')
                                ->seconds(false)
                                ->visible(fn (): bool => static::canPublish())
                                ->helperText('Set a future date to schedule. Empty = now when published.'),
                            Forms\Components\Placeholder::make('publishing_help')
                                ->label('Publishing')
                                ->visible(fn (): bool => ! static::canPublish())
                                ->content('Save your draft, then press “Submit for review”. An approver checks it and puts it on the website.'),
                            Forms\Components\Select::make('project_id')
                                ->label('Internal project (optional)')
                                ->options(fn (): array => Project::query()->orderByDesc('id')->pluck('title', 'id')->all())
                                ->searchable()
                                ->helperText('For your reference only. Nothing from it is shown publicly.'),
                            Forms\Components\Placeholder::make('live_url')
                                ->label('Page address')
                                ->visibleOn('edit')
                                ->content(fn (?PortfolioProject $record): ?HtmlString => ($url = static::websiteUrl($record))
                                    ? new HtmlString('<a href="'.e($url).'" target="_blank" rel="noopener" style="text-decoration:underline">'.e($url).'</a>')
                                    : null),
                            Forms\Components\Hidden::make('created_by')->default(fn () => auth()->id()),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('primaryCategory'))
            ->reorderable('sort', fn (): bool => static::canPublish())
            ->defaultSort('sort')
            ->columns([
                Tables\Columns\ImageColumn::make('thumbnail')
                    ->label('')
                    ->state(fn (PortfolioProject $record): ?string => $record->thumbnailUrl())
                    ->height(48)
                    ->extraImgAttributes(['style' => 'border-radius:8px;object-fit:cover;width:72px']),
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (PortfolioProject $record): string => $record->websitePath()),
                Tables\Columns\TextColumn::make('primaryCategory.name')->label('Category')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('status')->formatStateUsing(fn (string $state): string => PortfolioProject::STATUSES[$state] ?? $state),
                Tables\Columns\IconColumn::make('is_featured')->label('Featured')->boolean(),
                Tables\Columns\TextColumn::make('is_published')
                    ->label('Website')
                    ->badge()
                    ->state(fn (PortfolioProject $record): string => match (true) {
                        ! $record->is_published => PortfolioProject::REVIEW_STATUSES[$record->review_status] ?? 'Draft',
                        $record->published_at?->isFuture() => 'Scheduled',
                        default => 'Live',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Live' => 'success',
                        'Scheduled' => 'info',
                        'Waiting for review' => 'warning',
                        'Changes requested' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('seo_check')
                    ->label('SEO')
                    ->state(fn (PortfolioProject $record): string => static::seoIssues($record) ? count(static::seoIssues($record)).' to fix' : 'Good')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Good' ? 'success' : 'warning')
                    ->tooltip(fn (PortfolioProject $record): ?string => implode(' · ', static::seoIssues($record)) ?: null),
                Tables\Columns\TextColumn::make('updated_at')->label('Updated')->since()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('primary_category_id')->label('Category')->relationship('primaryCategory', 'name'),
                Tables\Filters\TernaryFilter::make('is_published')->label('Published'),
                Tables\Filters\SelectFilter::make('review_status')->label('Review')->options(PortfolioProject::REVIEW_STATUSES),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->visible(fn (PortfolioProject $record): bool => $record->is_published)
                    ->url(fn (PortfolioProject $record): ?string => static::websiteUrl($record), shouldOpenInNewTab: true),
                Tables\Actions\EditAction::make()
                    ->label(fn (PortfolioProject $record): string => static::canPublish() && $record->review_status === 'pending' ? 'Review' : 'Edit'),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\RestoreAction::make(),
            ]);
    }

    /**
     * Simple checklist for things that hurt search ranking or sharing.
     *
     * @return list<string>
     */
    public static function seoIssues(PortfolioProject $project): array
    {
        $title = $project->seo_title ?: $project->title.' | CivoraX Infra';
        $description = $project->seo_description ?: $project->short_description;

        return array_values(array_filter([
            mb_strlen($title) > 60 ? 'SEO title over 60 characters' : null,
            mb_strlen((string) $description) < 70 ? 'Description too short (aim for 120–160)' : null,
            mb_strlen((string) $description) > 160 ? 'Description over 160 characters' : null,
            $project->coverUrl() === null ? 'No cover image' : null,
            collect($project->gallery ?? [])->contains(fn (array $item): bool => blank($item['alt'] ?? null)) ? 'Gallery photo without alt text' : null,
            mb_strlen((string) $project->overview) < 300 ? 'Overview under 300 characters (thin content)' : null,
            blank($project->location) ? 'No location (helps local search)' : null,
        ]));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPortfolioProjects::route('/'),
            'create' => Pages\CreatePortfolioProject::route('/create'),
            'edit' => Pages\EditPortfolioProject::route('/{record}/edit'),
        ];
    }
}
