<?php

namespace App\Http\Controllers;

use App\Http\Requests\Gradebook\AdjustGradeRequest;
use App\Models\Course;
use App\Models\Grade;
use App\Services\GradeAdjustmentService;
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

    /**
     * Record what this course should report for one grade.
     */
    public function adjust(GradeAdjustmentService $adjustments, AdjustGradeRequest $request, Course $course, Grade $grade)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $grade->course_id === (int) $course->id, 404);

        $result = $adjustments->apply($grade, $request->validated(), $request->user());

        return response()->json([
            'message' => $result['changed']
                ? 'Grade adjusted. The recorded score is unchanged.'
                : 'No changes to save.',
            'changed' => $result['changed'],
            'adjustment' => $grade->fresh()->latestAdjustment?->toArray(),
        ]);
    }

    /**
     * The course's trail of grade decisions, newest first.
     */
    public function adjustmentLog(Request $request, Course $course)
    {
        $this->authorize('manage', $course);

        return response()->json(['adjustments' => $this->gradebook->recentAdjustments($course)]);
    }
}
