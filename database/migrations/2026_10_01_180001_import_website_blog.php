<?php

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\PortfolioProject;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;

/**
 * Brings the blog that was hard-coded in the website (civorax-infra src/entities/blog) into the database,
 * so the site looks the same after switching to the API. Runs only on an empty blog.
 * Author photos stay as website paths (e.g. /images/team/...) until uploaded in the admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        $file = database_path('data/blog_import.json');

        if (BlogCategory::query()->exists() || ! is_file($file)) {
            return;
        }

        $data = json_decode((string) file_get_contents($file), true);

        $categories = collect($data['blogCategories'] ?? [])->values()->mapWithKeys(fn (array $c, int $i): array => [
            $c['slug'] => BlogCategory::create(['slug' => $c['slug'], 'name' => $c['label'], 'sort' => $i + 1]),
        ]);

        foreach ($data['blogPosts'] ?? [] as $p) {
            $category = $categories[$p['category']] ?? null;

            if ($category === null) {
                continue;
            }

            $post = new BlogPost([
                'slug' => $p['slug'],
                'title' => $p['title'],
                'excerpt' => $p['excerpt'],
                'content' => trim($p['content']),
                'blog_category_id' => $category->id,
                'tags' => $p['tags'] ?? [],
                'cover_url' => $p['coverImage'] ?? null,
                'cover_alt' => $p['title'],
                'seo_title' => $p['seoTitle'] ?? null,
                'seo_description' => $p['seoDescription'] ?? null,
                'faqs' => $p['faqs'] ?? [],
                'related_services' => $p['relatedServices'] ?? [],
                'show_estimator_cta' => (bool) ($p['relatedEstimatorAnchor'] ?? false),
                'is_featured' => (bool) ($p['featured'] ?? false),
                'author_name' => $p['author']['name'] ?? null,
                'author_role' => $p['author']['role'] ?? null,
                'author_avatar_url' => $p['author']['avatar'] ?? null,
                'status' => 'published',
                'published_at' => Carbon::parse($p['publishedAt'])->startOfDay(),
            ]);
            $post->created_at = Carbon::parse($p['publishedAt'])->startOfDay();
            $post->updated_at = Carbon::parse($p['updatedAt'] ?? $p['publishedAt'])->startOfDay();
            $post->timestamps = false;
            $post->save();

            $projectIds = PortfolioProject::query()->whereIn('slug', collect($p['relatedProjects'] ?? [])->pluck('slug'))->pluck('id');
            $post->portfolioProjects()->sync($projectIds);
        }
    }

    public function down(): void
    {
        // Imported content is kept; dropping the tables (previous migration) removes it.
    }
};
