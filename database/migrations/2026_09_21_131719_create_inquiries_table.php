<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiries', function (Blueprint $table) {
            $table->id();
            $table->string('fullname');
            $table->string('address')->nullable();
            $table->string('contact');
            $table->foreignId('inquiry_type_id')->constrained()->restrictOnDelete();
            $table->string('contact_channel');
            $table->foreignId('referred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('message');
            $table->string('status')->default('new');
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiries');
    }
};
