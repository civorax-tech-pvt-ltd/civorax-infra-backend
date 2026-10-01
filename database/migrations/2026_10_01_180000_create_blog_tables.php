<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The website blog: posts written in the admin (team members submit, approvers publish).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();
        });

        Schema::create('blog_posts', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('excerpt', 400);
            $table->longText('content'); // Markdown
            $table->foreignId('blog_category_id')->constrained()->restrictOnDelete();
            $table->json('tags')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('cover_url')->nullable();
            $table->string('cover_alt')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->json('faqs')->nullable();              // [{question, answer}]
            $table->json('related_services')->nullable();  // [{label, href}]
            $table->boolean('show_estimator_cta')->default(false);
            $table->boolean('is_featured')->default(false);
            // Author shown on the post (defaults from the writer's team profile).
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name')->nullable();
            $table->string('author_role')->nullable();
            $table->string('author_avatar_path')->nullable();
            $table->string('author_avatar_url')->nullable();
            // Workflow: draft → pending → published, or back to the writer with changes requested.
            $table->string('status')->default('draft');
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
        });

        Schema::create('blog_post_portfolio_project', function (Blueprint $table) {
            $table->foreignId('blog_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('portfolio_project_id')->constrained()->cascadeOnDelete();
            $table->primary(['blog_post_id', 'portfolio_project_id'], 'blog_post_portfolio_primary');
        });

        Permission::findOrCreate('approve_blog_posts', 'web');
        Role::query()->where('name', 'super_admin')->each(fn (Role $role) => $role->givePermissionTo('approve_blog_posts'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_post_portfolio_project');
        Schema::dropIfExists('blog_posts');
        Schema::dropIfExists('blog_categories');

        Permission::query()->where('name', 'approve_blog_posts')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
