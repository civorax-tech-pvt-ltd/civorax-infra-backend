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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A project shown publicly on the website's "Our Work", with its own SEO fields.
 * URL: /{locale}/our-work/{primary category slug}/{slug}.
 */
#[Fillable([
    'slug', 'title', 'short_description', 'overview', 'primary_category_id', 'status', 'size', 'is_featured',
    'location', 'year', 'client_label', 'services', 'thumbnail_path', 'thumbnail_url', 'cover_path', 'cover_url',
    'gallery', 'videos', 'challenge', 'solution', 'seo_title', 'seo_description', 'seo_keywords',
    'is_published', 'published_at', 'sort', 'project_id', 'created_by',
    'review_status', 'submitted_by', 'submitted_at', 'reviewed_by', 'reviewed_at', 'review_note',
])]
class PortfolioProject extends Model
{
    use LogsActivity, SoftDeletes;

    /**
     * @var array<string, string>
     */
    public const STATUSES = [
        'concept' => 'Concept',
        'ongoing' => 'Ongoing',
        'completed' => 'Completed',
    ];

    /**
     * Review workflow: team members submit, approvers ("approve_portfolio_projects") publish.
     *
     * @var array<string, string>
     */
    public const REVIEW_STATUSES = [
        'draft' => 'Draft',
        'pending' => 'Waiting for review',
        'changes_requested' => 'Changes requested',
        'published' => 'Published',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'concept', 'size' => 'small', 'is_featured' => false, 'is_published' => false, 'review_status' => 'draft', 'sort' => 0];

    protected static function booted(): void
    {
        static::saving(function (PortfolioProject $project): void {
            $project->slug = static::uniqueSlug($project->slug ?: $project->title, $project->getKey());

            if ($project->is_published && $project->published_at === null) {
                $project->published_at = now();
            }

            // Keep the review status in step with the publish switch (approvers can also use the switch).
            if ($project->is_published) {
                $project->review_status = 'published';
            } elseif ($project->review_status === 'published') {
                $project->review_status = 'draft';
            }
        });

        // The primary category is always one of its categories (for filters and category pages).
        static::saved(function (PortfolioProject $project): void {
            $project->categories()->syncWithoutDetaching([$project->primary_category_id]);
            RevalidateWebsite::portfolio();
        });

        static::deleted(fn () => RevalidateWebsite::portfolio());
    }

    protected function casts(): array
    {
        return [
            'services' => 'array',
            'gallery' => 'array',
            'videos' => 'array',
            'is_featured' => 'boolean',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'year' => 'integer',
        ];
    }

    public static function canPublish(?User $user): bool
    {
        return (bool) $user?->hasSitePower('approve_portfolio_projects');
    }

    /**
     * Approvers edit anything; others only drafts (or ones sent back), never what is live or under review.
     */
    public function isEditableBy(?User $user): bool
    {
        return static::canPublish($user) || in_array($this->review_status, ['draft', 'changes_requested'], true);
    }

    public function submit(User $by): void
    {
        if (! in_array($this->review_status, ['draft', 'changes_requested'], true)) {
            throw ValidationException::withMessages(['review_status' => 'Only drafts can be submitted for review.']);
        }

        $this->forceFill(['review_status' => 'pending', 'submitted_by' => $by->getKey(), 'submitted_at' => now(), 'review_note' => null])->save();

        Alerts::portfolioProjectSubmitted($this);
    }

    /**
     * Publish now, or on a future date (scheduled).
     */
    public function publish(User $by, ?string $at = null): void
    {
        if (! static::canPublish($by)) {
            throw ValidationException::withMessages(['review_status' => 'You are not allowed to publish portfolio projects.']);
        }

        $this->forceFill([
            'is_published' => true,
            'published_at' => $at ?? now(),
            'reviewed_by' => $by->getKey(),
            'reviewed_at' => now(),
        ])->save();

        Alerts::portfolioProjectReviewed($this);
    }

    public function requestChanges(User $by, string $note): void
    {
        if (! static::canPublish($by)) {
            throw ValidationException::withMessages(['review_status' => 'You are not allowed to review portfolio projects.']);
        }

        $this->forceFill(['review_status' => 'changes_requested', 'review_note' => $note, 'reviewed_by' => $by->getKey(), 'reviewed_at' => now()])->save();

        Alerts::portfolioProjectReviewed($this);
    }

    public function unpublish(): void
    {
        $this->forceFill(['is_published' => false])->save();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public static function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'project';
        $slug = $base;
        $n = 2;

        while (static::withTrashed()->where('slug', $slug)->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }

    /**
     * Published and live: published flag on and publish date reached.
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true)->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    /**
     * A stored upload path becomes a public URL; an external link is used as-is.
     */
    public static function imageUrl(?string $path, ?string $url = null): ?string
    {
        if (filled($path)) {
            return Str::startsWith($path, ['http://', 'https://']) ? $path : Storage::disk('public')->url($path);
        }

        return filled($url) ? $url : null;
    }

    public function thumbnailUrl(): ?string
    {
        return static::imageUrl($this->thumbnail_path, $this->thumbnail_url) ?? $this->coverUrl();
    }

    public function coverUrl(): ?string
    {
        return static::imageUrl($this->cover_path, $this->cover_url) ?? static::imageUrl($this->thumbnail_path, $this->thumbnail_url);
    }

    /**
     * @return list<array{url: string, alt: string}>
     */
    public function galleryImages(): array
    {
        return collect($this->gallery ?? [])
            ->map(fn (array $item): ?array => ($url = static::imageUrl($item['path'] ?? null, $item['url'] ?? null))
                ? ['url' => $url, 'alt' => filled($item['alt'] ?? null) ? $item['alt'] : $this->title]
                : null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<array{title: string, type: string, url: string}>
     */
    public function videoList(): array
    {
        return collect($this->videos ?? [])
            ->filter(fn (array $video): bool => filled($video['url'] ?? null))
            ->map(fn (array $video): array => [
                'title' => $video['title'] ?? $this->title,
                'type' => Str::contains($video['url'], ['youtube.com', 'youtu.be']) ? 'youtube' : 'mp4',
                'url' => $video['url'],
            ])
            ->values()
            ->all();
    }

    public function websitePath(): string
    {
        return "/our-work/{$this->primaryCategory?->slug}/{$this->slug}";
    }

    public function primaryCategory(): BelongsTo
    {
        return $this->belongsTo(PortfolioCategory::class, 'primary_category_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(PortfolioCategory::class, 'portfolio_category_project');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
