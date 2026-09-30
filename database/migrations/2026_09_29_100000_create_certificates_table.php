<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('number')->unique();
            $table->string('verification_code', 16)->unique();
            // Printed values are copied at issue time so later edits to the student or course never change a certificate.
            $table->string('student_name');
            $table->string('course_title');
            $table->string('grade')->nullable();
            $table->date('issued_on');
            $table->date('completed_on');
            $table->string('instructor_name')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('revoked_at')->nullable();
            $table->string('revoke_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('certificate_settings', function (Blueprint $table) {
            $table->id();
            $table->string('organization_name')->nullable();
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('pan_number')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('number_prefix')->default('CERT');
            $table->string('default_instructor')->nullable();
            $table->string('instructor_signature_path')->nullable();
            $table->string('director_name')->nullable();
            $table->string('director_title')->default('Director');
            $table->string('director_signature_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_settings');
        Schema::dropIfExists('certificates');
    }
};
