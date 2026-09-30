<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['course_id', 'student_id', 'enrolled_at', 'created_by'])]
class Enrollment extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['enrolled_at' => 'date'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function classSessions(): HasMany
    {
        return $this->hasMany(ClassSession::class, 'course_id', 'course_id');
    }

    public function coursePayments(): HasMany
    {
        return $this->hasMany(CoursePayment::class);
    }

    public function coursePaymentSubmissions(): HasMany
    {
        return $this->hasMany(CoursePaymentSubmission::class);
    }

    public function certificate(): HasOne
    {
        return $this->hasOne(Certificate::class);
    }
}
