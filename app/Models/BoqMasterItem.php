<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * The company-wide BOQ library ("estimate module"). Project BOQs copy items from here; a project keeps
 * its own copied rate, so editing a default rate only affects future projects.
 */
#[Fillable([
    'code', 'description', 'unit', 'default_rate', 'category', 'rate_includes_vat',
    'norms', 'is_active', 'rate_updated_at', 'created_by',
])]
class BoqMasterItem extends Model
{
    use LogsActivity;

    /**
     * @var array<string, string>
     */
    public const CATEGORIES = [
        'earthwork' => 'Earthwork',
        'concrete' => 'Concrete / RCC',
        'reinforcement' => 'Reinforcement',
        'formwork' => 'Formwork',
        'masonry' => 'Masonry',
        'plaster' => 'Plaster & finishing',
        'flooring' => 'Flooring & tiling',
        'woodwork' => 'Doors, windows & woodwork',
        'metalwork' => 'Metal work',
        'painting' => 'Painting',
        'plumbing' => 'Plumbing & sanitary',
        'electrical' => 'Electrical',
        'roofing' => 'Roofing & waterproofing',
        'interior' => 'Interior',
        'other' => 'Other',
    ];

    /**
     * @var list<string>
     */
    public const UNITS = ['m³', 'm²', 'm', 'rm', 'cft', 'sq.ft', 'rft', 'kg', 'MT', 'nos', 'set', 'point', 'LS', 'day'];

    protected static function booted(): void
    {
        static::saving(function (BoqMasterItem $item): void {
            if ($item->isDirty('default_rate')) {
                $item->rate_updated_at = now();
            }
        });

        // Items used by any project are deactivated, never deleted.
        static::deleting(fn (BoqMasterItem $item) => ! $item->isUsed());
    }

    protected function casts(): array
    {
        return [
            'default_rate' => 'decimal:2',
            'rate_includes_vat' => 'boolean',
            'norms' => 'array',
            'is_active' => 'boolean',
            'rate_updated_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty();
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function isUsed(): bool
    {
        return $this->boqItems()->exists();
    }

    public function label(): string
    {
        return trim(($this->code ? "{$this->code} · " : '').$this->description)." ({$this->unit}, Rs ".number_format((float) $this->default_rate, 2).')';
    }

    /**
     * Existing items whose code matches or whose description is very close, to warn before creating a duplicate.
     *
     * @return Collection<int, BoqMasterItem>
     */
    public static function similarTo(?string $code, ?string $description, ?int $ignoreId = null): Collection
    {
        $normal = fn (?string $text): string => preg_replace('/[^a-z0-9]+/', ' ', strtolower(trim((string) $text)));
        $wanted = $normal($description);

        if ($wanted === '' && blank($code)) {
            return new Collection;
        }

        return static::query()
            ->when($ignoreId, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->get()
            ->filter(function (BoqMasterItem $item) use ($code, $wanted, $normal): bool {
                if (filled($code) && $item->code !== null && strcasecmp($item->code, trim($code)) === 0) {
                    return true;
                }

                if ($wanted === '') {
                    return false;
                }

                similar_text($wanted, $normal($item->description), $percent);

                return $percent >= 85;
            })
            ->values();
    }

    /**
     * Merge a duplicate into this item: its project BOQ rows point here, and the duplicate is deactivated.
     */
    public function absorb(BoqMasterItem $duplicate): void
    {
        DB::transaction(function () use ($duplicate): void {
            $duplicate->boqItems()->update(['master_item_id' => $this->getKey()]);
            $duplicate->forceFill(['is_active' => false])->save();
        });
    }

    public function boqItems(): HasMany
    {
        return $this->hasMany(BoqItem::class, 'master_item_id');
    }
}
