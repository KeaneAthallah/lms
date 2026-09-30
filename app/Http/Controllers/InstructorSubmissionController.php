<?php

namespace App\Http\Controllers;

use App\Http\Requests\Assignment\GradeSubmissionRequest;
use App\Http\Resources\SubmissionResource;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Notifications\AssignmentGraded;
use App\Services\ProgressService;
use App\SubmissionStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InstructorSubmissionController extends Controller
{
    public function __construct(protected ProgressService $progress) {}

    public function index(Request $request, Course $course)
    {
        $this->authorize('manage', $course);

        $query = AssignmentSubmission::whereIn('assignment_id', $course->assignments()->pluck('id'))
            ->with(['student:id,name,email,avatar_path', 'assignment:id,title,max_score'])
            ->latest('submitted_at');

        if ($request->string('status')->toString() === 'pending') {
            $query->where('status', 'submitted');
        } elseif ($request->string('status')->toString() === 'graded') {
            $query->where('status', 'graded');
        }

        if ($search = $request->string('search')->trim()->toString()) {
            $query->whereHas('student', fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }

        $submissions = $query->paginate(15)->withQueryString();

        return response()->json([
            'pending_count' => AssignmentSubmission::whereIn('assignment_id', $course->assignments()->pluck('id'))->where('status', 'submitted')->count(),
            'graded_count' => AssignmentSubmission::whereIn('assignment_id', $course->assignments()->pluck('id'))->where('status', 'graded')->count(),
            'submissions' => SubmissionResource::collection($submissions)->resolve(),
            'meta' => [
                'current_page' => $submissions->currentPage(),
                'last_page' => $submissions->lastPage(),
                'total' => $submissions->total(),
            ],
        ]);
    }

    public function show(Request $request, Course $course, AssignmentSubmission $submission)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $submission->assignment?->course_id === (int) $course->id, 404);

        $submission->load(['student:id,name,email,avatar_path', 'assignment:id,title,max_score', 'grader:id,name']);

        return new SubmissionResource($submission);
    }

    public function grade(GradeSubmissionRequest $request, Course $course, AssignmentSubmission $submission)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $submission->assignment?->course_id === (int) $course->id, 404);

        $grader = $request->user();
        $grade = $request->input('grade');
        $feedback = $request->input('feedback');

        $assignment = $submission->assignment;
        $maxScore = (float) $assignment->max_score;
        $percentage = $maxScore > 0 ? round(((float) $grade / $maxScore) * 100, 2) : 0;

        // The submission, the grade ledger entry, the student's lesson progress
        // and the notification form one outcome. A partial failure would leave a
        // submission marked graded with no ledger row, or a completed lesson the
        // instructor never graded.
        DB::transaction(function () use ($submission, $assignment, $grader, $grade, $feedback, $maxScore, $percentage): void {
            $submission->update([
                'status' => SubmissionStatus::Graded,
                'grade' => $grade,
                'feedback' => $feedback,
                'graded_by' => $grader->id,
                'graded_at' => now(),
            ]);

            $assignment->course->grades()->updateOrCreate(
                [
                    'student_id' => $submission->student_id,
                    'source_type' => AssignmentSubmission::class,
                    'source_id' => $submission->id,
                ],
                [
                    'course_id' => $assignment->course_id,
                    'type' => 'assignment',
                    'score' => $grade,
                    'max_score' => $maxScore,
                    'percentage' => $percentage,
                    'feedback' => $feedback,
                    'graded_by' => $grader->id,
                    'graded_at' => now(),
                ]
            );

            if ($assignment->lesson) {
                $this->progress->completeLesson($assignment->lesson, $submission->student);
            }
        });

        $submission->student->notify((new AssignmentGraded($assignment, (float) $grade, $feedback))->afterCommit());

        return response()->json([
            'message' => 'Submission graded.',
            'submission' => (new SubmissionResource($submission->fresh()->load(['student:id,name,email', 'assignment:id,title,max_score', 'grader:id,name'])))->resolve(),
        ]);
    }

    public function downloadFile(Request $request, Course $course, AssignmentSubmission $submission, int $fileIndex): StreamedResponse
    {
        $this->authorize('manage', $course);
        abort_unless((int) $submission->assignment?->course_id === (int) $course->id, 404);

        $file = $submission->files[$fileIndex] ?? abort(404);

        $disk = Storage::disk('local');

        abort_unless($disk->exists($file['path']), 404);

        return $disk->download($file['path'], $file['name'] ?? basename($file['path']));
    }

    public function studentSubmissions(Request $request, Course $course, int $student)
    {
        $this->authorize('manage', $course);

        $submissions = AssignmentSubmission::whereHas('assignment', fn ($q) => $q->where('course_id', $course->id))
            ->where('student_id', $student)
            ->with(['assignment:id,title,max_score,instructions', 'grader:id,name'])
            ->latest('submitted_at')
            ->get();

        return SubmissionResource::collection($submissions);
    }
}
