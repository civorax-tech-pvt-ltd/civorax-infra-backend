<?php

namespace Tests\Feature;

use App\Filament\Resources\PortfolioProjectResource;
use App\Filament\Resources\PortfolioProjectResource\Pages\CreatePortfolioProject;
use App\Filament\Resources\PortfolioProjectResource\Pages\EditPortfolioProject;
use App\Models\PortfolioCategory;
use App\Models\PortfolioProject;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PortfolioApiTest extends TestCase
{
    use RefreshDatabase;

    protected PortfolioCategory $homes;

    protected PortfolioCategory $interiors;

    protected function setUp(): void
    {
        parent::setUp();

        // The import migration seeds the website's original portfolio; start these tests from a clean slate.
        PortfolioProject::query()->forceDelete();
        PortfolioCategory::query()->delete();

        $this->homes = PortfolioCategory::create(['name' => 'Home Concepts', 'slug' => 'home-concepts', 'sort' => 1]);
        $this->interiors = PortfolioCategory::create(['name' => 'Interior Concepts', 'slug' => 'interior-concepts', 'sort' => 2]);
    }

    protected function project(array $attributes = []): PortfolioProject
    {
        return PortfolioProject::create([
            'title' => 'Modern Home Itahari',
            'short_description' => 'A modern two-storey home.',
            'overview' => 'Full overview.',
            'primary_category_id' => $this->homes->id,
            'is_published' => true,
            ...$attributes,
        ]);
    }

    public function test_only_published_live_projects_are_public(): void
    {
        $live = $this->project(['title' => 'Live Home']);
        $this->project(['title' => 'Draft Home', 'is_published' => false]);
        $this->project(['title' => 'Scheduled Home', 'published_at' => now()->addWeek()]);

        $this->getJson('/api/v1/portfolio/projects')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'live-home')
            ->assertHeader('Cache-Control', 'max-age=300, public');

        $this->getJson('/api/v1/portfolio/projects/draft-home')->assertNotFound();
        $this->getJson("/api/v1/portfolio/projects/{$live->slug}")->assertOk();
    }

    public function test_slugs_are_unique_and_the_primary_category_is_always_attached(): void
    {
        $first = $this->project();
        $second = $this->project();

        $this->assertSame('modern-home-itahari', $first->slug);
        $this->assertSame('modern-home-itahari-2', $second->slug);
        $this->assertTrue($first->categories->contains($this->homes));
        $this->assertSame('/our-work/home-concepts/modern-home-itahari', $first->websitePath());
    }

    public function test_detail_has_seo_media_and_related_projects_and_lists_filter_by_category(): void
    {
        $villa = $this->project([
            'title' => 'Serenity Villa',
            'location' => 'Itahari',
            'services' => ['Architectural Design', '3D Visualization'],
            'cover_url' => 'https://cdn.example.com/cover.jpg',
            'gallery' => [['url' => 'https://cdn.example.com/1.jpg', 'alt' => 'Front elevation at dusk'], ['url' => 'https://cdn.example.com/2.jpg']],
            'videos' => [['title' => 'Walkthrough', 'url' => 'https://www.youtube.com/watch?v=abc']],
            'seo_title' => 'Serenity Villa | Modern House Design Itahari',
            'seo_keywords' => 'house design itahari, modern villa nepal',
        ]);
        $villa->categories()->attach($this->interiors);
        $related = $this->project(['title' => 'Sky Home']);
        $this->project(['title' => 'Interior Only', 'primary_category_id' => $this->interiors->id]);

        $this->getJson('/api/v1/portfolio/projects/serenity-villa')
            ->assertOk()
            ->assertJsonPath('data.primary_category', 'home-concepts')
            ->assertJsonPath('data.categories', ['home-concepts', 'interior-concepts'])
            ->assertJsonPath('data.thumbnail_url', 'https://cdn.example.com/cover.jpg') // falls back to the cover
            ->assertJsonPath('data.gallery.0.alt', 'Front elevation at dusk')
            ->assertJsonPath('data.gallery.1.alt', 'Serenity Villa') // alt falls back to the title
            ->assertJsonPath('data.videos.0.type', 'youtube')
            ->assertJsonPath('data.seo.keywords', ['house design itahari', 'modern villa nepal'])
            ->assertJsonPath('data.related.0.slug', $related->slug);

        $this->getJson('/api/v1/portfolio/projects?category=interior-concepts')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/portfolio/categories')
            ->assertJsonPath('data.0.slug', 'home-concepts')
            ->assertJsonPath('data.0.projects_count', 2);
    }

    public function test_hidden_categories_hide_their_projects(): void
    {
        $this->project(['title' => 'Interior Only', 'primary_category_id' => $this->interiors->id]);
        $this->interiors->update(['is_visible' => false]);

        $this->getJson('/api/v1/portfolio/projects')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/portfolio/categories')->assertJsonCount(1, 'data');
    }

    public function test_saving_tells_the_website_to_refresh_once_per_request(): void
    {
        Http::fake();
        config(['services.website.url' => 'https://civoraxinfra.test', 'services.website.revalidate_secret' => 's3cret']);

        $project = $this->project();
        $project->update(['title' => 'Renamed']);
        $this->app->terminate();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://civoraxinfra.test/api/revalidate'
            && $request->header('x-revalidate-secret')[0] === 's3cret'
            && $request['tag'] === 'portfolio');
    }

    public function test_no_ping_without_a_secret_configured(): void
    {
        Http::fake();
        config(['services.website.revalidate_secret' => null]);

        $this->project();
        $this->app->terminate();

        Http::assertNothingSent();
    }

    public function test_admins_create_projects_with_extra_categories_and_get_an_seo_checklist(): void
    {
        $admin = User::factory()->create(['phone' => '9800000000']);
        $admin->assignRole(Role::create(['name' => 'super_admin']));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(CreatePortfolioProject::class)
            ->fillForm([
                'title' => 'Newari Loft',
                'short_description' => 'Interior inspired by Newari craft.',
                'overview' => 'Overview text.',
                'primary_category_id' => $this->interiors->id,
                'categories' => [$this->homes->id],
                'is_published' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $project = PortfolioProject::sole();
        $this->assertSame('newari-loft', $project->slug);
        $this->assertEqualsCanonicalizing([$this->homes->id, $this->interiors->id], $project->categories->pluck('id')->all());
        $this->assertNotNull($project->published_at);
        $this->assertContains('No cover image', PortfolioProjectResource::seoIssues($project));

        // Team panel: follows the role's ticks in Shield › Roles.
        Filament::setCurrentPanel(Filament::getPanel('team'));
        $member = User::factory()->create(['phone' => '9811111111']);
        $member->assignRole(Role::create(['name' => 'marketing']));
        $this->actingAs($member);
        $this->assertFalse(PortfolioProjectResource::canAccess());

        Permission::findOrCreate('view_any_portfolio::project');
        Permission::findOrCreate('update_portfolio::project');
        $member->roles->first()->givePermissionTo(['view_any_portfolio::project', 'update_portfolio::project']);
        $member->refresh();

        $this->assertTrue(PortfolioProjectResource::canAccess());
        $this->assertFalse(PortfolioProjectResource::canCreate());
        $this->assertFalse(PortfolioProjectResource::canDelete($project));
        // Live projects can only be changed by approvers.
        $this->assertFalse(PortfolioProjectResource::canEdit($project));
        $project->update(['is_published' => false]);
        $this->assertTrue(PortfolioProjectResource::canEdit($project->fresh()));
    }

    public function test_team_projects_need_approval_before_going_live(): void
    {
        $admin = User::factory()->create(['phone' => '9800000000']);
        $admin->assignRole(Role::create(['name' => 'super_admin']));

        $role = Role::create(['name' => 'marketing']);
        foreach (['view_any', 'create', 'update'] as $action) {
            $role->givePermissionTo(Permission::findOrCreate("{$action}_portfolio::project"));
        }
        $member = User::factory()->create(['phone' => '9811111111']);
        $member->assignRole($role);

        Filament::setCurrentPanel(Filament::getPanel('team'));
        $this->actingAs($member);

        // Even if the publish switch is sent, a team member's project starts as a draft.
        Livewire::test(CreatePortfolioProject::class)
            ->fillForm([
                'title' => 'Dharan Duplex',
                'short_description' => 'Two-family home.',
                'overview' => 'Overview text.',
                'primary_category_id' => $this->homes->id,
            ])
            ->assertFormFieldIsHidden('is_published')
            ->call('create')
            ->assertHasNoFormErrors();

        $project = PortfolioProject::sole();
        $this->assertFalse($project->is_published);

        Livewire::test(EditPortfolioProject::class, ['record' => $project->getRouteKey()])
            ->assertActionHidden('publish')
            ->callAction('submit');

        $project->refresh();
        $this->assertSame('pending', $project->review_status);
        $this->assertFalse(PortfolioProjectResource::canEdit($project));
        $this->assertSame(1, $admin->notifications()->count());
        $this->getJson('/api/v1/portfolio/projects/dharan-duplex')->assertNotFound();

        // The approver sends it back, then publishes the resubmission.
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);
        Livewire::test(EditPortfolioProject::class, ['record' => $project->getRouteKey()])
            ->callAction('requestChanges', ['note' => 'Add a cover photo.']);
        $this->assertSame('changes_requested', $project->fresh()->review_status);
        $this->assertSame(1, $member->notifications()->count());

        $project->fresh()->submit($member);
        Livewire::test(EditPortfolioProject::class, ['record' => $project->getRouteKey()])
            ->callAction('publish', ['published_at' => null]);

        $project->refresh();
        $this->assertTrue($project->is_published);
        $this->assertSame('published', $project->review_status);
        $this->assertSame(2, $member->notifications()->count());
        $this->getJson('/api/v1/portfolio/projects/dharan-duplex')->assertOk();
    }
}
