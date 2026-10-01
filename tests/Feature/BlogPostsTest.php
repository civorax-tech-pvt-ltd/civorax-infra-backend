<?php

namespace Tests\Feature;

use App\Filament\Resources\BlogPostResource;
use App\Filament\Resources\BlogPostResource\Pages\CreateBlogPost;
use App\Filament\Resources\BlogPostResource\Pages\EditBlogPost;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\TeamMember;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BlogPostsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $writer;

    protected User $editor;

    protected BlogCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-10 06:00:00');

        // The import migration seeds the website's original posts; start from a clean slate.
        BlogPost::query()->forceDelete();
        BlogCategory::query()->delete();
        $this->category = BlogCategory::create(['name' => 'Cost & Budget', 'slug' => 'construction-cost']);

        $this->admin = User::factory()->create(['phone' => '9800000000']);
        $this->admin->assignRole(Role::create(['name' => 'super_admin']));

        $this->writer = $this->teamUser('9822222222', 'Sita Engineer', Role::create(['name' => 'engineer']));
        $this->editor = $this->teamUser('9833333333', 'Ram Editor', Role::create(['name' => 'marketing'])->givePermissionTo('approve_blog_posts'));
    }

    protected function teamUser(string $phone, string $name, Role $role): User
    {
        $user = User::factory()->create(['phone' => $phone]);
        $user->assignRole($role);
        TeamMember::create([
            'user_id' => $user->id, 'fullname' => $name, 'designation' => 'Civil Engineer', 'contact1' => $phone, 'marital_status' => 'Single',
            'national_id_path' => 'ids/id.jpg', 'bank_name' => 'Nabil', 'bank_account_name' => $name, 'bank_account_number' => '1',
            'created_by' => $this->admin->id,
        ]);

        return $user;
    }

    protected function blogPost(array $attributes = []): BlogPost
    {
        return BlogPost::create([
            'title' => 'House Cost in Itahari',
            'excerpt' => 'What a house costs.',
            'content' => "### Rates\n\nSome words.",
            'blog_category_id' => $this->category->id,
            'author_id' => $this->writer->id,
            'author_name' => 'Sita Engineer',
            ...$attributes,
        ]);
    }

    public function test_only_live_posts_are_public(): void
    {
        $this->blogPost(['title' => 'Live post', 'status' => 'published', 'published_at' => now()->subDay()]);
        $this->blogPost(['title' => 'Scheduled post', 'status' => 'published', 'published_at' => now()->addDay()]);
        $this->blogPost(['title' => 'Pending post', 'status' => 'pending']);
        $this->blogPost(['title' => 'Draft post']);

        $this->getJson('/api/v1/blog/posts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'live-post')
            ->assertJsonPath('data.0.category', 'construction-cost')
            ->assertJsonMissingPath('data.0.content');

        $this->getJson('/api/v1/blog/posts/pending-post')->assertNotFound();
        $this->getJson('/api/v1/blog/posts/scheduled-post')->assertNotFound();
        $this->getJson('/api/v1/blog/posts/live-post')
            ->assertOk()
            ->assertJsonPath('data.content', "### Rates\n\nSome words.")
            ->assertJsonPath('data.author.name', 'Sita Engineer')
            ->assertJsonMissingPath('data.review_note');

        $this->getJson('/api/v1/blog/categories')->assertOk()->assertJsonPath('data.0.posts_count', 1);
    }

    public function test_writer_drafts_and_submits_but_cannot_publish(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('team'));
        $this->actingAs($this->writer);

        Livewire::test(CreateBlogPost::class)
            ->assertFormSet(['author_name' => 'Sita Engineer', 'author_role' => 'Civil Engineer'])
            ->fillForm([
                'title' => 'Vastu tips for Dharan homes',
                'slug' => 'vastu-tips-for-dharan-homes',
                'blog_category_id' => $this->category->id,
                'excerpt' => 'Simple vastu tips.',
                'content' => 'Body text.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $post = BlogPost::query()->firstOrFail();
        $this->assertSame('draft', $post->status);
        $this->assertSame($this->writer->id, $post->author_id);

        Livewire::test(EditBlogPost::class, ['record' => $post->getRouteKey()])
            ->assertActionHidden('publish')
            ->assertActionVisible('submit')
            ->callAction('submit');

        $post->refresh();
        $this->assertSame('pending', $post->status);
        $this->assertNotNull($post->submitted_at);
        // Locked for the writer while under review, and approvers were told.
        $this->assertFalse(BlogPostResource::canEdit($post));
        $this->assertSame(1, $this->editor->notifications()->count());
        $this->assertSame(1, $this->admin->notifications()->count());
        $this->getJson('/api/v1/blog/posts/vastu-tips-for-dharan-homes')->assertNotFound();
    }

    public function test_approver_requests_changes_then_publishes(): void
    {
        $post = $this->blogPost(['status' => 'pending']);

        Filament::setCurrentPanel(Filament::getPanel('team'));
        $this->actingAs($this->editor);

        Livewire::test(EditBlogPost::class, ['record' => $post->getRouteKey()])
            ->callAction('requestChanges', ['note' => 'Add 2026 rates please.']);

        $post->refresh();
        $this->assertSame('changes_requested', $post->status);
        $this->assertSame(1, $this->writer->notifications()->count());

        // The writer can edit again and resubmit.
        $this->actingAs($this->writer);
        $this->assertTrue(BlogPostResource::canEdit($post));
        $post->submit($this->writer);
        $this->assertNull($post->fresh()->review_note);

        $this->actingAs($this->editor);
        Livewire::test(EditBlogPost::class, ['record' => $post->getRouteKey()])
            ->callAction('publish', ['published_at' => null]);

        $post->refresh();
        $this->assertSame('published', $post->status);
        $this->assertSame($this->editor->id, $post->reviewed_by);
        $this->getJson("/api/v1/blog/posts/{$post->slug}")->assertOk();

        // Published posts are no longer editable by the writer.
        $this->actingAs($this->writer);
        $this->assertFalse(BlogPostResource::canEdit($post));
    }

    public function test_admin_pages_render_in_both_panels(): void
    {
        $post = $this->blogPost(['status' => 'pending']);

        $this->actingAs($this->admin)->get(BlogPostResource::getUrl(panel: 'admin'))->assertOk()->assertSee('House Cost in Itahari');
        $this->get(BlogPostResource::getUrl('edit', ['record' => $post], panel: 'admin'))->assertOk()->assertSee('Request changes');
        $this->get(BlogPostResource::getUrl('create', panel: 'admin'))->assertOk();

        $this->actingAs($this->writer)->get(BlogPostResource::getUrl(panel: 'team'))->assertOk()->assertSee('House Cost in Itahari');
        $this->get(BlogPostResource::getUrl('create', panel: 'team'))->assertOk();
        // Under review: locked for the writer.
        $this->get(BlogPostResource::getUrl('edit', ['record' => $post], panel: 'team'))->assertForbidden();
    }

    public function test_writers_only_see_their_own_posts_and_cannot_publish_directly(): void
    {
        $this->blogPost(['title' => 'Mine']);
        $this->blogPost(['title' => 'Someone else', 'author_id' => $this->editor->id]);

        Filament::setCurrentPanel(Filament::getPanel('team'));
        $this->actingAs($this->writer);

        $this->assertSame(['Mine'], BlogPostResource::getEloquentQuery()->pluck('title')->all());
        $this->assertFalse(BlogPostResource::canPublish());

        $this->expectException(ValidationException::class);
        BlogPost::query()->firstOrFail()->publish($this->writer);
    }

    public function test_scheduled_publish_and_revalidation(): void
    {
        config(['services.website.url' => 'https://site.test', 'services.website.revalidate_secret' => 'secret']);
        Http::fake();

        $post = $this->blogPost();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);

        Livewire::test(EditBlogPost::class, ['record' => $post->getRouteKey()])
            ->callAction('publish', ['published_at' => '2026-10-20 08:00:00']);

        $this->assertSame('published', $post->fresh()->status);
        $this->getJson("/api/v1/blog/posts/{$post->slug}")->assertNotFound();

        app()->terminate();
        Http::assertSent(fn ($request): bool => $request->url() === 'https://site.test/api/revalidate' && $request['tag'] === 'blog');

        Carbon::setTestNow('2026-10-21 00:00:00');
        $this->getJson("/api/v1/blog/posts/{$post->slug}")->assertOk();
    }
}
