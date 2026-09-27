<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_pings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_member_id')->constrained()->cascadeOnDelete();
            $table->dateTime('recorded_at');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('accuracy');
            $table->boolean('matched')->default(false);
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('office_location_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('distance')->nullable();
            $table->timestamps();

            $table->index(['team_member_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_pings');
    }
};
