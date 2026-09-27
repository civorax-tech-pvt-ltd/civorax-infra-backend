<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_monthly_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_member_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->unsignedSmallInteger('present_days')->default(0);
            $table->unsignedSmallInteger('gps_days')->default(0);
            $table->unsignedSmallInteger('manual_days')->default(0);
            $table->decimal('total_hours', 7, 1)->default(0);
            $table->json('place_days')->nullable();
            $table->timestamps();

            $table->unique(['team_member_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_monthly_summaries');
    }
};
