<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('office_location_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            $table->unsignedInteger('closest_distance');
            $table->unsignedInteger('pings')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_visits');
    }
};
