<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Services\ProgressService;
use Illuminate\Http\Request;

class LessonProgressController extends Controller
{
    public function __construct(protected ProgressService $progress) {}

    /**
     * Update lesson progress:
     *  - { "complete": true }           mark the lesson complete
     *  - { "video_position": 60 }       record video playback position
     *  - {}                             mark the lesson as visited
     */
    public function update(Request $request, Lesson $lesson)
    {
        $user = $request->user();
        $this->authorize('view', $lesson);

        $videoPosition = (int) $request->integer('video_position');
        $complete = (bool) $request->boolean('complete');

        if ($complete) {
            $record = $this->progress->completeLesson($lesson, $user);
        } elseif ($lesson->type->value === 'video' && $videoPosition > 0) {
            $record = $this->progress->recordVideoProgress($lesson, $user, $videoPosition);
        } else {
            $record = $this->progress->markStarted($lesson, $user);
        }

        $courseProgress = $this->progress->recalculateCourseProgress($lesson->course, $user);

        return response()->json([
            'lesson_progress' => [
                'percent' => $record->progress_percent,
                'completed' => $record->isCompleted(),
                'video_position' => $record->video_position_seconds,
            ],
            'course_progress' => $courseProgress,
        ]);
    }
}
