<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\PortfolioProject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public, read-only blog for the website. Only published posts whose date has arrived are exposed;
 * drafts, posts under review and reviewer notes never leave the admin.
 */
class BlogController extends Controller
{
    public function categories(): JsonResponse
    {
        $categories = BlogCategory::query()
            ->visible()
            ->withCount(['posts' => fn (Builder $query) => $query->live()])
            ->get()
            ->map(fn (BlogCategory $category): array => [
                'id' => $category->id,
                'slug' => $category->slug,
                'name' => $category->name,
                'description' => $category->description,
                'seo_title' => $category->seo_title,
                'seo_description' => $category->seo_description,
                'posts_count' => $category->posts_count,
            ]);

        return $this->cached(['data' => $categories]);
    }

    public function index(Request $request): JsonResponse
    {
        $posts = $this->liveQuery()
            ->when($request->query('category'), fn (Builder $query, string $slug) => $query->whereHas('category', fn (Builder $q) => $q->where('slug', $slug)))
            ->when($request->boolean('featured'), fn (Builder $query) => $query->where('is_featured', true))
            ->get()
            ->map(fn (BlogPost $post): array => $this->summary($post));

        return $this->cached(['data' => $posts]);
    }

    public function show(string $slug): JsonResponse
    {
        $post = $this->liveQuery()->with('portfolioProjects.primaryCategory')->where('slug', $slug)->first();

        if ($post === null) {
            return response()->json(['message' => 'Post not found.'], 404);
        }

        // Same category first, then the newest of the rest.
        $related = $this->liveQuery()
            ->whereKeyNot($post->id)
            ->reorder()
            ->orderByRaw('CASE WHEN blog_category_id = ? THEN 0 ELSE 1 END', [$post->blog_category_id])
            ->orderByDesc('published_at')
            ->limit(3)
            ->get();

        return $this->cached(['data' => [
            ...$this->summary($post),
            'content' => $post->content,
            'seo' => [
                'title' => $post->seo_title,
                'description' => $post->seo_description,
            ],
            'faqs' => array_values(array_filter($post->faqs ?? [], fn (array $faq): bool => filled($faq['question'] ?? null) && filled($faq['answer'] ?? null))),
            'related_services' => array_values($post->related_services ?? []),
            'related_projects' => $post->portfolioProjects
                ->filter(fn (PortfolioProject $project): bool => $project->is_published && $project->published_at?->isFuture() !== true && $project->primaryCategory !== null)
                ->map(fn (PortfolioProject $project): array => [
                    'slug' => $project->slug,
                    'title' => $project->title,
                    'category' => $project->primaryCategory->slug,
                ])
                ->values(),
            'show_estimator_cta' => $post->show_estimator_cta,
            'related' => $related->map(fn (BlogPost $item): array => $this->summary($item))->values(),
        ]]);
    }

    protected function liveQuery(): Builder
    {
        return BlogPost::query()
            ->live()
            ->whereHas('category', fn (Builder $q) => $q->where('is_visible', true))
            ->with('category')
            ->orderByDesc('published_at');
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(BlogPost $post): array
    {
        return [
            'id' => $post->id,
            'slug' => $post->slug,
            'title' => $post->title,
            'excerpt' => $post->excerpt,
            'category' => $post->category->slug,
            'category_name' => $post->category->name,
            'tags' => array_values($post->tags ?? []),
            'cover_url' => $post->coverUrl(),
            'cover_alt' => $post->cover_alt,
            'author' => [
                'name' => $post->author_name,
                'role' => $post->author_role,
                'avatar' => $post->authorAvatar(),
            ],
            'is_featured' => $post->is_featured,
            'reading_minutes' => $post->readingMinutes(),
            'published_at' => $post->published_at?->toIso8601String(),
            'updated_at' => $post->updated_at?->toIso8601String(),
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
