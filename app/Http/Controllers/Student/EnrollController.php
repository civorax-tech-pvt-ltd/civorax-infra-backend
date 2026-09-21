<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class EnrollController extends Controller
{
    public function __invoke(Course $course): RedirectResponse
    {
        $user = Auth::user();
        $student = $user?->student;

        if (! $student) {
            abort(403, 'Only student accounts can enroll in courses.');
        }

        if ($course->status !== 'published') {
            abort(404);
        }

        $enrollment = $student->enrollments()
            ->where('course_id', $course->id)
            ->first();

        if (! $enrollment) {
            $enrollment = $student->enrollments()->create([
                'course_id' => $course->id,
                'enrolled_at' => now(),
                'created_by' => $user->id,
            ]);
        }

        return redirect("/student/enrollments/{$enrollment->id}");
    }
}
