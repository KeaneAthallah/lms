<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Services\GradebookService;
use Illuminate\Http\Request;

class InstructorGradebookController extends Controller
{
    public function __construct(protected GradebookService $gradebook) {}

    public function show(Request $request, Course $course)
    {
        $this->authorize('manage', $course);

        return response()->json($this->gradebook->courseGradebook($course));
    }
}
