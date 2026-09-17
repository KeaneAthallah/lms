<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Enrollment;
use Illuminate\Http\Request;

class InstructorStudentController extends Controller
{
    public function index(Request $request, Course $course)
    {
        $this->authorize('manage', $course);

        $query = Enrollment::where('course_id', $course->id)
            ->with(['student:id,name,email,avatar_path,created_at'])
            ->latest();

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        if ($search = $request->string('search')->trim()->toString()) {
            $query->whereHas('student', fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }

        $enrollments = $query->paginate(15)->withQueryString();

        return response()->json([
            'student_count' => Enrollment::where('course_id', $course->id)->count(),
            'completed_count' => Enrollment::where('course_id', $course->id)->where('status', 'completed')->count(),
            'enrollments' => $enrollments->map(fn (Enrollment $e): array => [
                'id' => $e->id,
                'status' => $e->status->value,
                'progress_percent' => $e->progress_percent,
                'completed_at' => $e->completed_at?->toISOString(),
                'enrolled_at' => $e->created_at?->toISOString(),
                'student' => [
                    'id' => $e->student->id,
                    'name' => $e->student->name,
                    'email' => $e->student->email,
                    'avatar' => $e->student->avatar_path ? asset('storage/'.$e->student->avatar_path) : null,
                ],
            ]),
            'meta' => [
                'current_page' => $enrollments->currentPage(),
                'last_page' => $enrollments->lastPage(),
                'total' => $enrollments->total(),
            ],
        ]);
    }
}
