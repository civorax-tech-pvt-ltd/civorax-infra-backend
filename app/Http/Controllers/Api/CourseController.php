<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class CourseController extends Controller
{
    public function index(): JsonResponse
    {
        $courses = Course::query()
            ->where('status', 'published')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Course $course) => $this->transform($course));

        return response()->json(['data' => $courses]);
    }

    public function show(Course $course): JsonResponse
    {
        if ($course->status !== 'published') {
            return response()->json(['message' => 'Course not found.'], 404);
        }

        return response()->json(['data' => $this->transform($course, withSyllabus: true)]);
    }

    private function transform(Course $course, bool $withSyllabus = false): array
    {
        $data = [
            'id' => $course->id,
            'title' => $course->title,
            'description' => $course->description,
            'type' => $course->type,
            'duration' => $course->duration,
            'fee' => (float) $course->fee,
            'discount_fee' => $course->discount_fee !== null ? (float) $course->discount_fee : null,
            'cover_image_url' => $course->cover_image ? Storage::disk('public')->url($course->cover_image) : null,
        ];

        if ($withSyllabus) {
            $data['syllabus'] = $course->syllabus;
        }

        return $data;
    }
}
