<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PortfolioCategory;
use App\Models\PortfolioProject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public, read-only portfolio for the website's "Our Work" pages. Only published projects are exposed,
 * and never anything from the internal client project they may be linked to.
 */
class PortfolioController extends Controller
{
    public function categories(): JsonResponse
    {
        $categories = PortfolioCategory::query()
            ->visible()
            ->withCount(['projects' => fn (Builder $query) => $query->published()])
            ->get()
            ->map(fn (PortfolioCategory $category): array => [
                'id' => $category->id,
                'slug' => $category->slug,
                'name' => $category->name,
                'description' => $category->description,
                'seo_title' => $category->seo_title,
                'seo_description' => $category->seo_description,
                'sort' => $category->sort,
                'projects_count' => $category->projects_count,
                'updated_at' => $category->updated_at?->toIso8601String(),
            ]);

        return $this->cached(['data' => $categories]);
    }

    public function index(Request $request): JsonResponse
    {
        $projects = $this->publishedQuery()
            ->when($request->query('category'), fn (Builder $query, string $slug) => $query->whereHas('categories', fn (Builder $q) => $q->where('slug', $slug)))
            ->when($request->boolean('featured'), fn (Builder $query) => $query->where('is_featured', true))
            ->get()
            ->map(fn (PortfolioProject $project): array => $this->summary($project));

        return $this->cached(['data' => $projects]);
    }

    public function show(string $slug): JsonResponse
    {
        $project = $this->publishedQuery()->where('slug', $slug)->first();

        if ($project === null) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        $categoryIds = $project->categories->pluck('id');
        // Same main category first, then any shared category.
        $related = $this->publishedQuery()
            ->whereKeyNot($project->id)
            ->whereHas('categories', fn (Builder $q) => $q->whereKey($categoryIds))
            ->reorder()
            ->orderByRaw('CASE WHEN primary_category_id = ? THEN 0 ELSE 1 END', [$project->primary_category_id])
            ->orderByDesc('is_featured')
            ->orderBy('sort')
            ->limit(3)
            ->get();

        return $this->cached(['data' => [
            ...$this->summary($project),
            'overview' => $project->overview,
            'client' => $project->client_label,
            'services' => array_values($project->services ?? []),
            'gallery' => $project->galleryImages(),
            'videos' => $project->videoList(),
            'challenge' => $project->challenge,
            'solution' => $project->solution,
            'seo' => [
                'title' => $project->seo_title,
                'description' => $project->seo_description,
                'keywords' => $project->seo_keywords
                    ? array_values(array_filter(array_map('trim', explode(',', $project->seo_keywords))))
                    : [],
            ],
            'related' => $related->map(fn (PortfolioProject $item): array => $this->summary($item))->values(),
        ]]);
    }

    protected function publishedQuery(): Builder
    {
        return PortfolioProject::query()
            ->published()
            ->whereHas('primaryCategory', fn (Builder $q) => $q->where('is_visible', true))
            ->with(['primaryCategory', 'categories'])
            ->orderByDesc('is_featured')
            ->orderBy('sort')
            ->orderByDesc('year')
            ->orderByDesc('published_at');
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(PortfolioProject $project): array
    {
        return [
            'id' => $project->id,
            'slug' => $project->slug,
            'title' => $project->title,
            'short_description' => $project->short_description,
            'primary_category' => $project->primaryCategory->slug,
            'primary_category_name' => $project->primaryCategory->name,
            'categories' => $project->categories->where('is_visible', true)->sortBy('sort')->pluck('slug')->values(),
            'status' => $project->status,
            'size' => $project->size,
            'is_featured' => $project->is_featured,
            'location' => $project->location,
            'year' => $project->year,
            'thumbnail_url' => $project->thumbnailUrl(),
            'cover_url' => $project->coverUrl(),
            'published_at' => $project->published_at?->toIso8601String(),
            'updated_at' => $project->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Short public caching; the website also caches and is told to refresh on every change.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function cached(array $payload): JsonResponse
    {
        return response()->json($payload)->header('Cache-Control', 'public, max-age=300');
    }
}
