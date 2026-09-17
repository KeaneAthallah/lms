<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Services\EnrollmentService;
use Illuminate\Http\Request;

class EnrollmentController extends Controller
{
    public function __construct(protected EnrollmentService $enrollments) {}

    public function store(Request $request, Course $course)
    {
        $this->authorize('enroll', $course);

        $enrollment = $this->enrollments->enroll($request->user(), $course);

        return response()->json([
            'message' => 'You are now enrolled in this course.',
            'enrollment' => [
                'id' => $enrollment->id,
                'status' => $enrollment->status->value,
                'progress_percent' => $enrollment->progress_percent,
            ],
            'course' => [
                'id' => $course->id,
                'title' => $course->title,
                'slug' => $course->slug,
            ],
        ], 201);
    }
}
