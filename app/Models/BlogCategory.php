<?php

namespace App\Models;

use App\Listeners\RevalidateWebsite;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A blog category (website /blog/category/{slug}).
 */
#[Fillable(['name', 'slug', 'description', 'seo_title', 'seo_description', 'sort', 'is_visible'])]
class BlogCategory extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['is_visible' => true, 'sort' => 0];

    protected static function booted(): void
    {
        static::saved(fn () => RevalidateWebsite::tag('blog'));
        static::deleted(fn () => RevalidateWebsite::tag('blog'));
    }

    protected function casts(): array
    {
        return ['is_visible' => 'boolean'];
    }

    public function scopeVisible(Builder $query): void
    {
        $query->where('is_visible', true)->orderBy('sort')->orderBy('name');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(BlogPost::class);
    }
}
