<?php

namespace App\Http\Controllers;

use App\Http\Resources\QuizRecoveryResource;
use App\Models\Quiz;
use App\Services\QuizDiagnosisService;
use Illuminate\Http\Request;

class QuizRecoveryController extends Controller
{
    public function show(Request $request, Quiz $quiz)
    {
        $this->authorize('view', $quiz);

        $attempt = $quiz->attempts()
            ->where('student_id', $request->user()->id)
            ->whereNotNull('submitted_at')
            ->orderByDesc('submitted_at')
            ->first();

        if ($attempt === null) {
            return QuizRecoveryResource::make([
                'has_attempt' => false,
                'quiz' => [
                    'id' => $quiz->id,
                    'title' => $quiz->title,
                ],
            ]);
        }

        $this->authorize('view', $attempt);

        return QuizRecoveryResource::make([
            'has_attempt' => true,
            'quiz' => [
                'id' => $quiz->id,
                'title' => $quiz->title,
            ],
            'diagnosis' => (new QuizDiagnosisService)->diagnose($attempt),
        ]);
    }
}
