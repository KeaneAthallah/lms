<?php

namespace App\Http\Controllers;

use App\Http\Resources\QuizReadinessResource;
use App\Models\Quiz;
use App\Services\LearningInsightService;
use Illuminate\Http\Request;

class QuizReadinessController extends Controller
{
    public function show(Request $request, Quiz $quiz)
    {
        $this->authorize('view', $quiz);

        $readiness = (new LearningInsightService)->quizReadinessFor($request->user(), $quiz);

        if ($readiness === null) {
            $readiness = [
                'state' => 'unknown',
                'reason' => 'This quiz is not attached to a lesson, so readiness cannot be measured.',
                'attempts_used' => $quiz->attemptsFor($request->user()),
                'quiz' => [
                    'id' => $quiz->id,
                    'title' => $quiz->title,
                    'passing_score' => (float) $quiz->passing_score,
                    'time_limit_minutes' => $quiz->time_limit_minutes,
                ],
                'lesson' => null,
                'lesson_id' => null,
                'cta' => ['to' => "/quiz/{$quiz->id}", 'label' => 'Take quiz'],
            ];
        }

        return QuizReadinessResource::make($readiness);
    }
}
