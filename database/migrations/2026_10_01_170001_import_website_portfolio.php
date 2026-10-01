<?php

use App\Models\PortfolioCategory;
use App\Models\PortfolioProject;
use Illuminate\Database\Migrations\Migration;

/**
 * Brings the portfolio that was hard-coded in the website (civorax-infra src/entities/projects) into the
 * database, so the site looks the same after switching to the API. Runs only on an empty portfolio.
 * Images stay as their current links until real photos are uploaded in the admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (PortfolioCategory::query()->exists()) {
            return;
        }

        $categories = collect([
            ['home-concepts', 'Home Concepts', 'Residential concepts designed for modern and sustainable living.'],
            ['interior-concepts', 'Interior Concepts', 'Interior spaces focused on functionality, aesthetics, and comfort.'],
            ['commercial', 'Commercial', 'Commercial buildings, offices, retail spaces, and business environments.'],
            ['renovation', 'Renovation', 'Modern renovation and restoration projects that preserve architectural value.'],
            ['3d-visualization', '3D Visualization', 'Photorealistic architectural visualizations and conceptual renderings.'],
        ])->mapWithKeys(fn (array $c, int $i): array => [$c[0] => PortfolioCategory::create([
            'slug' => $c[0], 'name' => $c[1], 'description' => $c[2], 'sort' => $i + 1,
            'seo_title' => "{$c[1]} House Designs in Nepal",
        ])]);

        $img = fn (string $id, int $w): string => "https://images.unsplash.com/{$id}?auto=format&fit=crop&w={$w}&q=85";

        $projects = [
            [
                'slug' => 'himalayan-serenity-villa', 'title' => 'The Himalayan Serenity Villa',
                'short_description' => 'A masterclass in contemporary Nepali residence balancing mountain views with sustainable passive solar design.',
                'overview' => 'The Himalayan Serenity Villa is a conceptual residential project designed to blend modern architecture with the surrounding Himalayan landscape. The design emphasizes natural lighting, cross ventilation, passive solar strategies, and strong indoor-outdoor connections.',
                'categories' => ['home-concepts', '3d-visualization'], 'status' => 'ongoing', 'size' => 'large',
                'location' => 'Pokhara, Nepal', 'year' => 2026, 'client_label' => 'Private Residence',
                'services' => ['Architectural Design', 'Concept Planning', '3D Visualization', 'Landscape Design'],
                'image' => 'photo-1600585154340-be6161a56a0c',
                'extra' => ['photo-1600607688969-a5bfcd646154', 'photo-1600566753190-17f0baa2a6c3'],
                'challenge' => 'Design a luxurious residence while preserving panoramic mountain views and reducing environmental impact.',
                'solution' => 'The villa uses passive solar orientation, expansive glazing, natural materials, and integrated landscaping to maximize sustainability and visual harmony.',
                'seo_title' => 'The Himalayan Serenity Villa | Residential Concept | CivoraX Infra',
                'seo_description' => 'Explore the Himalayan Serenity Villa, a modern residential concept showcasing sustainable architecture and mountain-inspired living.',
            ],
            [
                'slug' => 'neo-newari-loft', 'title' => 'Neo-Newari Loft',
                'short_description' => 'Modern urban interiors inspired by traditional Newari craftsmanship.',
                'overview' => 'Neo-Newari Loft reimagines traditional Newari design principles inside a modern apartment environment using handcrafted wood detailing and open-plan spaces.',
                'categories' => ['interior-concepts'], 'status' => 'concept', 'size' => 'small', 'location' => 'Kathmandu', 'year' => 2026,
                'services' => ['Interior Design', 'Furniture Planning', '3D Visualization'], 'image' => 'photo-1616046229478-9901c5536a45',
                'seo_title' => 'Neo-Newari Loft | Interior Concept | CivoraX Infra',
                'seo_description' => 'A contemporary interior concept inspired by timeless Newari architecture.',
            ],
            [
                'slug' => 'vertex-it-plaza', 'title' => 'Vertex IT Plaza',
                'short_description' => 'Eco-friendly commercial infrastructure for digital enterprises.',
                'overview' => 'A conceptual commercial office complex designed for technology companies with energy-efficient systems and flexible workspaces.',
                'categories' => ['commercial'], 'status' => 'completed', 'size' => 'small', 'location' => 'Kathmandu', 'year' => 2025,
                'services' => ['Commercial Design', 'Structural Planning'], 'image' => 'photo-1486406146926-c627a92ad1ab',
            ],
            [
                'slug' => 'heritage-revive-kantipath', 'title' => 'Heritage Revive: Kantipath',
                'short_description' => "Structural restoration preserving Kathmandu's architectural identity.",
                'overview' => 'A renovation concept focused on reinforcing historical buildings while maintaining their original cultural significance.',
                'categories' => ['renovation'], 'status' => 'ongoing', 'size' => 'small',
                'services' => ['Renovation', 'Structural Assessment'], 'image' => 'photo-1556909114-f6e7ad7d3136',
            ],
            [
                'slug' => 'lalitpur-sky-garden', 'title' => 'Lalitpur Sky Garden',
                'short_description' => 'Vertical green architecture bringing biodiversity into urban environments.',
                'overview' => 'A futuristic 3D visualization proposing high-rise vertical forests for dense urban areas.',
                'categories' => ['3d-visualization'], 'status' => 'concept', 'size' => 'small',
                'services' => ['3D Visualization', 'Concept Design'], 'image' => 'photo-1486325212027-8081e485255e',
            ],
        ];

        foreach ($projects as $i => $p) {
            $project = PortfolioProject::create([
                ...collect($p)->only(['slug', 'title', 'short_description', 'overview', 'status', 'size', 'location', 'year', 'client_label', 'services', 'challenge', 'solution', 'seo_title', 'seo_description'])->all(),
                'primary_category_id' => $categories[$p['categories'][0]]->id,
                'is_featured' => true,
                'thumbnail_url' => $img($p['image'], 1000),
                'cover_url' => $img($p['image'], 1800),
                'gallery' => collect([$p['image'], ...($p['extra'] ?? [])])->map(fn (string $id): array => ['url' => $img($id, 1800), 'alt' => $p['title']])->all(),
                'is_published' => true,
                'published_at' => now(),
                'sort' => $i + 1,
            ]);

            $project->categories()->sync(collect($p['categories'])->map(fn (string $slug): int => $categories[$slug]->id)->all());
        }
    }

    public function down(): void
    {
        // Imported content is ordinary data; leave it to the table migration's rollback.
    }
};
