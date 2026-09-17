<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use Illuminate\Http\Request;

class AdminEnrollmentController extends Controller
{
    public function index(Request $request)
    {
        $query = Enrollment::with(['course:id,title,slug', 'student:id,name,email'])
            ->latest();

        if ($request->has('status') && in_array($request->string('status')->toString(), ['active', 'completed'], true)) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('course', fn ($c) => $c->where('title', 'like', "%{$search}%"))
                    ->orWhereHas('student', fn ($s) => $s->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            });
        }

        $enrollments = $query->paginate(15)->withQueryString();

        return response()->json([
            'enrollments' => $enrollments->map(fn (Enrollment $e): array => [
                'id' => $e->id,
                'status' => $e->status->value,
                'progress_percent' => $e->progress_percent,
                'completed_at' => $e->completed_at?->toISOString(),
                'created_at' => $e->created_at?->toISOString(),
                'course' => ['id' => $e->course->id, 'title' => $e->course->title, 'slug' => $e->course->slug],
                'student' => ['id' => $e->student->id, 'name' => $e->student->name, 'email' => $e->student->email],
            ]),
            'meta' => [
                'current_page' => $enrollments->currentPage(),
                'last_page' => $enrollments->lastPage(),
                'total' => $enrollments->total(),
            ],
        ]);
    }

    public function destroy(Request $request, Enrollment $enrollment)
    {
        if ($enrollment->status->value === 'completed') {
            return response()->json(['message' => 'Completed enrollments cannot be removed.'], 409);
        }

        $enrollment->delete();

        return response()->json(['message' => 'Enrollment removed.']);
    }
}
