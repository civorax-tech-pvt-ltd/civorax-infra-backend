<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedTinyInteger('progress')->default(0)->after('status');
        });

        Schema::table('project_milestones', function (Blueprint $table) {
            $table->string('phase')->nullable()->after('title');
            $table->unsignedTinyInteger('progress')->default(0)->after('status');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('milestone_id')->nullable()->after('project_id')
                ->constrained('project_milestones')->nullOnDelete();
            $table->unsignedTinyInteger('weight')->default(1)->after('status');

            // Tasks generated from milestone templates start unassigned, undated and without details.
            $table->unsignedBigInteger('assignee_id')->nullable()->change();
            $table->text('description')->nullable()->change();
            $table->dateTime('due_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('milestone_id');
            $table->dropColumn('weight');
        });

        Schema::table('project_milestones', function (Blueprint $table) {
            $table->dropColumn(['phase', 'progress']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('progress');
        });
    }
};
