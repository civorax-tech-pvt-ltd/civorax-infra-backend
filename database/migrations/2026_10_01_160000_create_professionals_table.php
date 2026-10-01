<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Directory of professionals the company can call on: engineers, architects, masons, electricians…
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('professionals', function (Blueprint $table) {
            $table->id();
            $table->string('fullname');
            $table->string('professional_type');
            $table->string('contact', 20)->unique();
            $table->string('alt_contact', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('address');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedTinyInteger('years_of_experience')->nullable();
            $table->string('photo_path')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_available')->default(true);
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['professional_type', 'is_available']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('professionals');
    }
};
