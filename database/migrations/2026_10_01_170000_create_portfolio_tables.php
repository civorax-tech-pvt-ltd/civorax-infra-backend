<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The public portfolio ("Our Work" on the website): showcase projects and their categories, with SEO fields.
 * Separate from internal client projects, which hold private data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_categories', function (Blueprint $table) {
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

        Schema::create('portfolio_projects', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('short_description', 300);
            $table->text('overview');
            $table->foreignId('primary_category_id')->constrained('portfolio_categories')->restrictOnDelete();
            $table->string('status')->default('concept'); // concept | ongoing | completed
            $table->string('size')->default('small');      // large | small (card size in the grid)
            $table->boolean('is_featured')->default(false);
            $table->string('location')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('client_label')->nullable();     // public wording, e.g. "Private Residence"
            $table->json('services')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('thumbnail_url')->nullable();    // or an external image link
            $table->string('cover_path')->nullable();
            $table->string('cover_url')->nullable();
            $table->json('gallery')->nullable();            // [{path?, url?, alt}]
            $table->json('videos')->nullable();             // [{title, url}]
            $table->text('challenge')->nullable();
            $table->text('solution')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->string('seo_keywords')->nullable();
            $table->boolean('is_published')->default(false);
            $table->dateTime('published_at')->nullable();
            // Team members submit; approvers publish: draft → pending → published, or changes_requested.
            $table->string('review_status')->default('draft');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete(); // internal project it showcases (not public)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_published', 'is_featured', 'sort']);
        });

        Schema::create('portfolio_category_project', function (Blueprint $table) {
            $table->foreignId('portfolio_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('portfolio_project_id')->constrained()->cascadeOnDelete();
            $table->primary(['portfolio_category_id', 'portfolio_project_id'], 'portfolio_cat_proj_primary');
        });

        Permission::findOrCreate('approve_portfolio_projects', 'web');
        Role::query()->where('name', 'super_admin')->each(fn (Role $role) => $role->givePermissionTo('approve_portfolio_projects'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_category_project');
        Schema::dropIfExists('portfolio_projects');
        Schema::dropIfExists('portfolio_categories');

        Permission::query()->where('name', 'approve_portfolio_projects')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
