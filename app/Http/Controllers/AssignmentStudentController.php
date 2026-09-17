<?php

namespace App\Http\Controllers;

use App\Http\Requests\Assignment\SubmitAssignmentRequest;
use App\Http\Resources\AssignmentResource;
use App\Http\Resources\SubmissionResource;
use App\Models\Assignment;
use App\Models\Course;
use App\Notifications\NewAssignmentSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AssignmentStudentController extends Controller
{
    public function index(Request $request, Course $course)
    {
        $user = $request->user();
        $this->authorize('view', $course);

        $assignments = $course->assignments()
            ->with(['course:id,title,slug', 'submissions' => fn ($q) => $q->where('student_id', $user->id)])
            ->withCount('submissions')
            ->where('status', 'active')
            ->orderByDesc('created_at')
            ->get();

        return AssignmentResource::collection($assignments);
    }

    public function show(Request $request, Assignment $assignment)
    {
        $user = $request->user();
        $this->authorize('view', $assignment);

        $assignment->load(['course:id,title,slug', 'submissions' => fn ($q) => $q->where('student_id', $user->id)])
            ->loadCount('submissions');

        return new AssignmentResource($assignment);
    }

    public function submit(SubmitAssignmentRequest $request, Assignment $assignment)
    {
        $user = $request->user();
        $this->authorize('submit', $assignment);

        $existing = $assignment->submissions()->where('student_id', $user->id)->first();

        if ($existing?->isGraded()) {
            throw ValidationException::withMessages([
                'submission' => ['This submission has already been graded and cannot be replaced.'],
            ]);
        }

        $files = [];
        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                $path = $file->store('submissions/'.$assignment->id.'/'.$user->id, 'local');
                $files[] = [
                    'name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'size' => $file->getSize(),
                    'type' => $file->extension(),
                ];
            }
        }

        if ($existing) {
            foreach ($existing->files ?? [] as $oldFile) {
                Storage::disk('local')->delete($oldFile['path']);
            }

            $existing->update([
                'content' => $request->input('content'),
                'files' => $files,
                'version' => $existing->version + 1,
                'status' => 'submitted',
                'submitted_at' => now(),
                'grade' => null,
                'feedback' => null,
                'graded_by' => null,
                'graded_at' => null,
            ]);
            $submission = $existing;
        } else {
            $submission = $assignment->submissions()->create([
                'student_id' => $user->id,
                'content' => $request->input('content'),
                'files' => $files,
                'version' => 1,
                'status' => 'submitted',
                'submitted_at' => now(),
            ]);
        }

        $assignment->course->instructor->notify(new NewAssignmentSubmission($submission->load('assignment')));

        return response()->json([
            'message' => 'Your submission has been saved.',
            'submission' => (new SubmissionResource($submission))->resolve(),
        ], 201);
    }

    public function mySubmission(Request $request, Assignment $assignment)
    {
        $user = $request->user();
        $this->authorize('view', $assignment);

        $submission = $assignment->submissions()
            ->with('grader')
            ->where('student_id', $user->id)
            ->first();

        return $submission ? new SubmissionResource($submission) : response()->json(['data' => null]);
    }
}
