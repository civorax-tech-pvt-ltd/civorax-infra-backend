<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A material tracked by quantity (only a handful: cement, rod, bricks, sand, aggregate …).
 */
#[Fillable(['name', 'unit', 'is_active', 'sort'])]
class KeyMaterial extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort')->orderBy('name');
    }

    public function label(): string
    {
        return "{$this->name} ({$this->unit})";
    }

    /**
     * @return array<int, string>
     */
    public static function options(): array
    {
        return static::query()->active()->get()->mapWithKeys(fn (KeyMaterial $material): array => [$material->id => $material->label()])->all();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
