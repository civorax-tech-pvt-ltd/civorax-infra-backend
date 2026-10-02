<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A BOQ library category (Earthwork, Concrete / RCC, Tile work, …). Items store its key.
 */
#[Fillable(['key', 'name', 'sort'])]
class BoqCategory extends Model
{
    use LogsActivity;

    protected static function booted(): void
    {
        static::creating(function (BoqCategory $category): void {
            $category->name = Str::squish($category->name);
            $category->key = $category->key ?: static::uniqueKey($category->name);
            // New ones go just before "Other".
            $category->sort = $category->sort ?: (int) static::query()->where('key', '!=', 'other')->max('sort') + 1;
        });
    }

    public static function uniqueKey(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $key = $base;
        $n = 2;

        while (static::query()->where('key', $key)->exists()) {
            $key = "{$base}-{$n}";
            $n++;
        }

        return $key;
    }

    /**
     * Returns the existing category with this name (any capitals) or creates it.
     */
    public static function findOrCreateByName(string $name): self
    {
        $name = Str::squish($name);

        return static::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
            ?? static::create(['name' => $name]);
    }

    /**
     * @return array<string, string> key => name, in display order ("Other" last)
     */
    public static function options(): array
    {
        return static::query()->orderBy('sort')->orderBy('name')->pluck('name', 'key')->all();
    }

    public function items(): HasMany
    {
        return $this->hasMany(BoqMasterItem::class, 'category', 'key');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
