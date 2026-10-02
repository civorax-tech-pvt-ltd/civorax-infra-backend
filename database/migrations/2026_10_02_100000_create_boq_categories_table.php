<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * BOQ categories become a managed list (add, rename, reorder) instead of a fixed one in code.
 * Items keep storing the category key, so the built-in keys ("concrete", "masonry", …) stay valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boq_categories', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('name', 100);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        $builtIn = [
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
        ];

        $sort = 0;
        foreach ($builtIn as $key => $name) {
            DB::table('boq_categories')->insert(['key' => $key, 'name' => $name, 'sort' => ++$sort, 'created_at' => now(), 'updated_at' => now()]);
        }

        // Custom categories typed in earlier were stored by name: give them a key and point their items at it.
        DB::table('boq_master_items')
            ->whereNotNull('category')
            ->whereNotIn('category', [...array_keys($builtIn), 'other'])
            ->distinct()
            ->pluck('category')
            ->each(function (string $name) use (&$sort): void {
                $key = Str::slug($name) ?: 'category-'.($sort + 1);

                if (! DB::table('boq_categories')->where('key', $key)->exists()) {
                    DB::table('boq_categories')->insert(['key' => $key, 'name' => $name, 'sort' => ++$sort, 'created_at' => now(), 'updated_at' => now()]);
                }

                DB::table('boq_master_items')->where('category', $name)->update(['category' => $key]);
            });

        DB::table('boq_categories')->insert(['key' => 'other', 'name' => 'Other', 'sort' => 999, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_categories');
    }
};
