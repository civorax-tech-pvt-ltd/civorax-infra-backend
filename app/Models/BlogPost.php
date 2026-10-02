<?php

namespace App\Models;

use App\Listeners\RevalidateWebsite;
use App\Notifications\Alerts;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A website blog post (Markdown). Team members write and submit; approvers (super admins and roles given
 * "approve_blog_posts") publish or send it back. Only published posts reach the website.
 */
#[Fillable([
    'slug', 'title', 'excerpt', 'content', 'blog_category_id', 'tags', 'cover_path', 'cover_url', 'cover_alt',
    'seo_title', 'seo_description', 'faqs', 'related_services', 'show_estimator_cta', 'is_featured',
    'author_id', 'author_name', 'author_role', 'author_avatar_path', 'author_avatar_url',
    'status', 'submitted_at', 'published_at', 'reviewed_by', 'reviewed_at', 'review_note',
])]
class BlogPost extends Model
{
    use LogsActivity, SoftDeletes;

    /**
     * @var array<string, string>
     */
    public const STATUSES = [
        'draft' => 'Draft',
        'pending' => 'Waiting for review',
        'changes_requested' => 'Changes requested',
        'published' => 'Published',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'draft', 'is_featured' => false, 'show_estimator_cta' => false];

    protected static function booted(): void
    {
        static::saving(function (BlogPost $post): void {
            $post->slug = static::uniqueSlug($post->slug ?: $post->title, $post->getKey());
        });

        // Only changes that can affect the public site need a refresh.
        static::saved(function (BlogPost $post): void {
            if ($post->status === 'published' || $post->getOriginal('status') === 'published' || $post->wasChanged('status')) {
                RevalidateWebsite::tag('blog');
            }
        });

        static::deleted(fn () => RevalidateWebsite::tag('blog'));
    }

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'faqs' => 'array',
            'related_services' => 'array',
            'show_estimator_cta' => 'boolean',
            'is_featured' => 'boolean',
            'submitted_at' => 'datetime',
            'published_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public static function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'post';
        $slug = $base;
        $n = 2;

        while (static::withTrashed()->where('slug', $slug)->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }

    /**
     * Live on the website: published and its date reached (future dates are scheduled).
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', 'published')->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    public function isEditableBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->hasSitePower('approve_blog_posts')) {
            return true;
        }

        // Writers edit their own posts until they are published (or while sent back to them).
        return (int) $this->author_id === (int) $user->getKey() && in_array($this->status, ['draft', 'changes_requested'], true);
    }

    public static function canPublish(?User $user): bool
    {
        return (bool) $user?->hasSitePower('approve_blog_posts');
    }

    public function submit(User $by): void
    {
        if (! in_array($this->status, ['draft', 'changes_requested'], true)) {
            throw ValidationException::withMessages(['status' => 'Only drafts can be submitted for review.']);
        }

        $this->forceFill(['status' => 'pending', 'submitted_at' => now(), 'review_note' => null])->save();

        Alerts::blogPostSubmitted($this);
    }

    /**
     * Publish now, or on a future date (scheduled).
     */
    public function publish(User $by, ?string $at = null): void
    {
        if (! static::canPublish($by)) {
            throw ValidationException::withMessages(['status' => 'You are not allowed to publish blog posts.']);
        }

        $this->forceFill([
            'status' => 'published',
            'published_at' => $at ?? $this->published_at ?? now(),
            'reviewed_by' => $by->getKey(),
            'reviewed_at' => now(),
        ])->save();

        Alerts::blogPostReviewed($this);
    }

    public function requestChanges(User $by, string $note): void
    {
        if (! static::canPublish($by)) {
            throw ValidationException::withMessages(['status' => 'You are not allowed to review blog posts.']);
        }

        $this->forceFill(['status' => 'changes_requested', 'review_note' => $note, 'reviewed_by' => $by->getKey(), 'reviewed_at' => now()])->save();

        Alerts::blogPostReviewed($this);
    }

    public function unpublish(): void
    {
        $this->forceFill(['status' => 'draft'])->save();
    }

    /**
     * About 200 words a minute, at least one minute.
     */
    public function readingMinutes(): int
    {
        return max(1, (int) ceil(str_word_count(strip_tags((string) $this->content)) / 200));
    }

    public static function imageUrl(?string $path, ?string $url = null): ?string
    {
        return PortfolioProject::imageUrl($path, $url);
    }

    public function coverUrl(): ?string
    {
        return static::imageUrl($this->cover_path, $this->cover_url);
    }

    public function authorAvatar(): ?string
    {
        return static::imageUrl($this->author_avatar_path, $this->author_avatar_url);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class, 'blog_category_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function portfolioProjects(): BelongsToMany
    {
        return $this->belongsToMany(PortfolioProject::class, 'blog_post_portfolio_project');
    }
}
