<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BlogPostResource\Pages;
use App\Filament\Resources\Concerns\SiteAccess;
use App\Models\BlogPost;
use App\Models\User;
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
 * Website blog. Any team member can write and submit a post; super admins and roles given "Blog posts" in
 * Approval Settings publish it (now or scheduled) or send it back with a note. Writers never publish.
 */
class BlogPostResource extends Resource
{
    use SiteAccess;

    protected static ?string $model = BlogPost::class;

    protected static ?string $navigationIcon = 'heroicon-o-newspaper';

    protected static ?string $navigationGroup = 'Website';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Blog posts';

    protected static ?string $modelLabel = 'blog post';

    protected static ?string $recordTitleAttribute = 'title';

    public static function canPublish(): bool
    {
        return BlogPost::canPublish(static::siteUser());
    }

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
        return $record->isEditableBy(static::siteUser());
    }

    public static function canDelete(Model $record): bool
    {
        // Writers can throw away their own unpublished drafts; approvers can remove anything.
        return static::canPublish() || ($record->isEditableBy(static::siteUser()) && $record->published_at === null);
    }

    public static function canRestore(Model $record): bool
    {
        return static::canPublish();
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canPublish()) {
            return null;
        }

        $count = BlogPost::query()->where('status', 'pending')->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function websiteUrl(?BlogPost $post, string $locale = 'en'): ?string
    {
        return $post ? rtrim(config('services.website.url'), '/')."/{$locale}/blog/{$post->slug}" : null;
    }

    /**
     * Author shown on a new post: the writer's team profile.
     *
     * @return array<string, mixed>
     */
    public static function authorDefaults(?User $user): array
    {
        return [
            'author_id' => $user?->getKey(),
            'author_name' => $user?->teamMember?->fullname ?? $user?->name,
            'author_role' => $user?->teamMember?->designation,
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Placeholder::make('review_feedback')
                ->hiddenLabel()
                ->columnSpanFull()
                ->visible(fn (?BlogPost $record): bool => $record?->status === 'changes_requested' && filled($record->review_note))
                ->content(fn (BlogPost $record): HtmlString => new HtmlString(
                    '<div style="padding:12px 14px;border-radius:10px;background:rgb(245 158 11 / .12);border:1px solid rgb(245 158 11 / .4)">'
                    .'<strong>Changes requested'.($record->reviewer ? ' by '.e($record->reviewer->name) : '').':</strong> '
                    .nl2br(e($record->review_note)).'<br><small>Edit the post, then press “Submit for review” again.</small></div>'
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
                                ->afterStateUpdated(function (Set $set, ?string $state, ?BlogPost $record): void {
                                    if ($record?->status !== 'published') {
                                        $set('slug', Str::slug((string) $state));
                                    }
                                }),
                            Forms\Components\TextInput::make('slug')
                                ->label('URL slug')
                                ->required()
                                ->alphaDash()
                                ->maxLength(150)
                                ->unique(BlogPost::class, 'slug', ignoreRecord: true)
                                ->disabled(fn (?BlogPost $record): bool => $record?->status === 'published' && ! static::canPublish())
                                ->helperText('Page address: /blog/{slug}. Don\'t change it after publishing: links and Google rankings use it.'),
                            Forms\Components\Select::make('blog_category_id')
                                ->label('Category')
                                ->relationship('category', 'name')
                                ->required()
                                ->preload()
                                ->createOptionForm(fn (Form $form) => BlogCategoryResource::form($form))
                                ->createOptionAction(fn (Forms\Components\Actions\Action $action) => $action->visible(fn (): bool => BlogCategoryResource::canCreate())),
                            Forms\Components\TagsInput::make('tags')
                                ->placeholder('Add a tag and press Enter')
                                ->suggestions(['Construction Cost', 'BOQ', 'House Design', 'Vastu', 'Building Permit', 'Koshi Province', 'Materials', 'Interior', 'Renovation']),
                            Forms\Components\Textarea::make('excerpt')
                                ->label('Summary (card)')
                                ->required()
                                ->maxLength(400)
                                ->rows(2)
                                ->helperText('One or two sentences shown on the blog card and under the title.')
                                ->columnSpanFull(),
                            Forms\Components\MarkdownEditor::make('content')
                                ->required()
                                ->columnSpanFull()
                                ->fileAttachmentsDisk('public')
                                ->fileAttachmentsDirectory('blog')
                                ->disableToolbarButtons(['codeBlock'])
                                ->helperText('Use “###” headings for sections; Google reads them as the outline of the article.'),
                        ]),
                    Forms\Components\Tabs\Tab::make('Cover & author')
                        ->icon('heroicon-o-photo')
                        ->columns(2)
                        ->schema([
                            Forms\Components\FileUpload::make('cover_path')
                                ->label('Cover image')
                                ->helperText('Banner on the post and the share image on Facebook/WhatsApp. About 1600 px wide.')
                                ->image()
                                ->imageEditor()
                                ->imageResizeMode('contain')
                                ->imageResizeTargetWidth('1800')
                                ->disk('public')
                                ->directory('blog')
                                ->maxSize(10240),
                            Forms\Components\Group::make([
                                Forms\Components\TextInput::make('cover_url')
                                    ->label('…or image link')
                                    ->url()
                                    ->maxLength(500)
                                    ->placeholder('https://…')
                                    ->helperText('Used only when no file is uploaded.'),
                                Forms\Components\TextInput::make('cover_alt')
                                    ->label('Describe the image (alt text)')
                                    ->maxLength(160),
                            ]),
                            Forms\Components\TextInput::make('author_name')
                                ->label('Author name (shown)')
                                ->required()
                                ->maxLength(100),
                            Forms\Components\TextInput::make('author_role')
                                ->label('Author title')
                                ->placeholder('e.g. Civil Engineer')
                                ->maxLength(100),
                            Forms\Components\FileUpload::make('author_avatar_path')
                                ->label('Author photo')
                                ->image()
                                ->avatar()
                                ->imageEditor()
                                ->disk('public')
                                ->directory('blog/authors')
                                ->maxSize(4096),
                        ]),
                    Forms\Components\Tabs\Tab::make('Extras')
                        ->icon('heroicon-o-squares-plus')
                        ->columns(2)
                        ->schema([
                            Forms\Components\Repeater::make('faqs')
                                ->label('FAQs')
                                ->helperText('Shown at the end of the post and can appear as questions directly in Google results.')
                                ->columnSpanFull()
                                ->defaultItems(0)
                                ->reorderable()
                                ->collapsible()
                                ->addActionLabel('Add question')
                                ->itemLabel(fn (array $state): ?string => $state['question'] ?? null)
                                ->schema([
                                    Forms\Components\TextInput::make('question')->required()->maxLength(200),
                                    Forms\Components\Textarea::make('answer')->required()->rows(2)->maxLength(1000),
                                ]),
                            Forms\Components\Repeater::make('related_services')
                                ->label('Related services (links)')
                                ->defaultItems(0)
                                ->columns(2)
                                ->addActionLabel('Add link')
                                ->schema([
                                    Forms\Components\TextInput::make('label')->required()->maxLength(80),
                                    Forms\Components\TextInput::make('href')->label('Link')->required()->default('/services')->maxLength(200),
                                ]),
                            Forms\Components\Select::make('portfolioProjects')
                                ->label('Related projects (Our Work)')
                                ->relationship('portfolioProjects', 'title')
                                ->multiple()
                                ->preload(),
                            Forms\Components\Toggle::make('show_estimator_cta')
                                ->label('Show the cost estimator box')
                                ->inline(false),
                        ]),
                    Forms\Components\Tabs\Tab::make('SEO')
                        ->icon('heroicon-o-magnifying-glass')
                        ->schema([
                            Forms\Components\TextInput::make('seo_title')
                                ->label('SEO title')
                                ->maxLength(70)
                                ->live(debounce: 500)
                                ->placeholder(fn (Get $get): string => ($get('title') ?: 'Post').' | CivoraX Infra')
                                ->helperText(fn (?string $state): string => 'The blue headline in Google. '.mb_strlen((string) $state).'/60 characters. Empty = post title.'),
                            Forms\Components\Textarea::make('seo_description')
                                ->label('SEO description')
                                ->maxLength(320)
                                ->rows(3)
                                ->live(debounce: 500)
                                ->helperText(fn (?string $state): string => 'The grey text under it. '.mb_strlen((string) $state).'/160 characters. Empty = summary.'),
                            Forms\Components\Placeholder::make('google_preview')
                                ->label('Google preview')
                                ->content(fn (Get $get): HtmlString => new HtmlString(
                                    '<div style="max-width:600px;font-family:Arial,sans-serif;padding:12px 14px;border:1px solid rgb(0 0 0 / .1);border-radius:10px;background:#fff">'
                                    .'<div style="font-size:12px;color:#202124">civoraxinfra.com › blog › '.e((string) ($get('slug') ?: 'post')).'</div>'
                                    .'<div style="font-size:19px;color:#1a0dab;margin:2px 0;line-height:1.3">'.e(Str::limit((string) ($get('seo_title') ?: (($get('title') ?: 'Post title').' | CivoraX Infra')), 60)).'</div>'
                                    .'<div style="font-size:13px;color:#4d5156;line-height:1.5">'.e(Str::limit((string) ($get('seo_description') ?: $get('excerpt') ?: 'Description…'), 160)).'</div>'
                                    .'</div>'
                                )),
                        ]),
                    Forms\Components\Tabs\Tab::make('Publishing')
                        ->icon('heroicon-o-globe-alt')
                        ->columns(2)
                        ->visible(fn (): bool => static::canPublish())
                        ->schema([
                            Forms\Components\Toggle::make('is_featured')
                                ->label('Featured (top of the blog)')
                                ->inline(false),
                            Forms\Components\DateTimePicker::make('published_at')
                                ->label('Publish date')
                                ->seconds(false)
                                ->helperText('A future date schedules the post. Use the Publish button to put it live.'),
                            Forms\Components\Placeholder::make('live_url')
                                ->label('Page address')
                                ->visible(fn (?BlogPost $record): bool => $record?->status === 'published')
                                ->content(fn (?BlogPost $record): ?HtmlString => ($url = static::websiteUrl($record))
                                    ? new HtmlString('<a href="'.e($url).'" target="_blank" rel="noopener" style="text-decoration:underline">'.e($url).'</a>')
                                    : null),
                        ]),
                ]),
        ]);
    }

    public static function statusLabel(BlogPost $post): string
    {
        return $post->status === 'published' && $post->published_at?->isFuture()
            ? 'Scheduled'
            : (BlogPost::STATUSES[$post->status] ?? $post->status);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('category'))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                Tables\Columns\ImageColumn::make('cover')
                    ->label('')
                    ->state(fn (BlogPost $record): ?string => $record->coverUrl())
                    ->height(48)
                    ->extraImgAttributes(['style' => 'border-radius:8px;object-fit:cover;width:72px']),
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->weight('bold')
                    ->wrap()
                    ->description(fn (BlogPost $record): string => "/blog/{$record->slug}"),
                Tables\Columns\TextColumn::make('category.name')->label('Category')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('author_name')->label('Author')->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->state(fn (BlogPost $record): string => static::statusLabel($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Published' => 'success',
                        'Scheduled' => 'info',
                        'Waiting for review' => 'warning',
                        'Changes requested' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('is_featured')->label('Featured')->boolean()->toggleable(),
                Tables\Columns\TextColumn::make('published_at')->label('Published')->date()->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('updated_at')->label('Updated')->since()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(BlogPost::STATUSES),
                Tables\Filters\SelectFilter::make('blog_category_id')->label('Category')->relationship('category', 'name'),
                Tables\Filters\TrashedFilter::make()->visible(fn (): bool => static::canPublish()),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->visible(fn (BlogPost $record): bool => $record->status === 'published')
                    ->url(fn (BlogPost $record): ?string => static::websiteUrl($record), shouldOpenInNewTab: true),
                Tables\Actions\EditAction::make()
                    ->label(fn (BlogPost $record): string => static::canPublish() && $record->status === 'pending' ? 'Review' : 'Edit'),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\RestoreAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);

        // Approvers see everything; writers see only their own posts.
        return static::canPublish() ? $query : $query->whereNull('deleted_at')->where('author_id', auth()->id());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBlogPosts::route('/'),
            'create' => Pages\CreateBlogPost::route('/create'),
            'edit' => Pages\EditBlogPost::route('/{record}/edit'),
        ];
    }
}
