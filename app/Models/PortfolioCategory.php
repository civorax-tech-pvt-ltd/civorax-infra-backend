<?php

namespace App\Models;

use App\Listeners\RevalidateWebsite;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A filter / landing page on the website's "Our Work" (e.g. /our-work/home-concepts).
 */
#[Fillable(['name', 'slug', 'description', 'seo_title', 'seo_description', 'sort', 'is_visible'])]
class PortfolioCategory extends Model
{
    use LogsActivity;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['is_visible' => true, 'sort' => 0];

    protected static function booted(): void
    {
        static::saved(fn () => RevalidateWebsite::portfolio());
        static::deleted(fn () => RevalidateWebsite::portfolio());
    }

    protected function casts(): array
    {
        return ['is_visible' => 'boolean'];
    }

    public function scopeVisible(Builder $query): void
    {
        $query->where('is_visible', true)->orderBy('sort')->orderBy('name');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(PortfolioProject::class, 'portfolio_category_project');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
