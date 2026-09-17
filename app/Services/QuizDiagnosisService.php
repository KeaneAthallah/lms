<?php

namespace App\Services;

use App\Models\QuizAttempt;

/**
 * Explains a student's mistakes on a completed quiz attempt.
 *
 * Every quiz is bound to a single lesson, which lives inside a course section -
 * that section title is the "concept" the questions exercise. The diagnosis
 * reports accuracy on that concept, the specific misconceptions (the wrong
 * option chosen and its explanation), and a deterministic recovery path.
 */
class QuizDiagnosisService
{
    public const STATUS_STRONG = 'strong';

    public const STATUS_GOOD = 'good';

    public const STATUS_CAUTION = 'caution';

    public const STATUS_REVIEW = 'review';

    private const STRONG_ACCURACY = 90;

    private const GOOD_ACCURACY = 75;

    private const CAUTION_ACCURACY = 60;

    /**
     * @return array<string, mixed>
     */
    public function diagnose(QuizAttempt $attempt): array
    {
        $attempt->load(['quiz.lesson.section', 'quiz.lesson.course:id,slug', 'quiz.questions.options', 'answers']);

        $quiz = $attempt->quiz;
        $lesson = $quiz->lesson;

        $concept = $lesson?->section?->title ?? $quiz->title;
        $courseSlug = $lesson?->course?->slug ?? $quiz->course?->slug;

        $total = $quiz->questions->count();
        $correct = $attempt->answers->filter(fn ($answer) => (bool) $answer->is_correct)->count();
        $accuracy = $total === 0 ? 0.0 : round($correct / $total * 100, 1);

        $conceptStatus = $this->statusFor($accuracy);

        $misconceptions = $this->collectMisconceptions($attempt);

        $scorePercentage = (float) $attempt->score_percentage;
        $passing = (float) $quiz->passing_score;
        $needsRecovery = $total > 0 && ($accuracy < $passing || ! $attempt->passed);

        $recommendations = [];
        if ($lesson !== null) {
            $recommendations[] = [
                'type' => 'review',
                'to' => "/learn/{$courseSlug}/{$lesson->id}",
                'label' => "Review \"{$lesson->title}\"",
            ];
        }
        $recommendations[] = [
            'type' => 'retake',
            'to' => "/quiz/{$quiz->id}",
            'label' => 'Retake quiz',
        ];

        return [
            'quiz' => [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'passing_score' => $passing,
            ],
            'attempt' => [
                'score_percentage' => $scorePercentage,
                'passed' => (bool) $attempt->passed,
                'submitted_at' => $attempt->submitted_at?->toIso8601String(),
            ],
            'concept' => $concept,
            'accuracy' => $accuracy,
            'status' => $conceptStatus,
            'correct' => $correct,
            'total' => $total,
            'needs_recovery' => $needsRecovery,
            'why' => $this->why($concept, $correct, $total, $accuracy),
            'summary' => $this->summary($accuracy, $passing, $attempt->passed),
            'misconceptions' => $misconceptions,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectMisconceptions(QuizAttempt $attempt): array
    {
        $misconceptions = [];

        foreach ($attempt->quiz->questions as $question) {
            $answer = $attempt->answers->first(fn ($row) => (int) $row->quiz_question_id === (int) $question->id);

            if ($answer === null || (bool) $answer->is_correct) {
                continue;
            }

            if ($question->type->value === 'short_answer') {
                continue;
            }

            $chosen = $question->options->first(fn ($option) => (int) $option->id === (int) $answer->answer);
            $correctOption = $question->options->first(fn ($option) => (bool) $option->is_correct);

            if ($chosen === null) {
                continue;
            }

            $misconceptions[] = [
                'question_id' => $question->id,
                'question_text' => $question->question_text,
                'submitted_answer_text' => $chosen->option_text,
                'correct_answer_text' => $correctOption?->option_text,
                'explanation' => $chosen->explanation ?: ($correctOption?->explanation ?? null),
            ];
        }

        return $misconceptions;
    }

    protected function statusFor(float $accuracy): string
    {
        return match (true) {
            $accuracy >= self::STRONG_ACCURACY => self::STATUS_STRONG,
            $accuracy >= self::GOOD_ACCURACY => self::STATUS_GOOD,
            $accuracy >= self::CAUTION_ACCURACY => self::STATUS_CAUTION,
            default => self::STATUS_REVIEW,
        };
    }

    protected function why(string $concept, int $correct, int $total, float $accuracy): string
    {
        if ($total === 0) {
            return "There are no questions to measure on \"{$concept}\".";
        }

        if ($accuracy >= 100) {
            return "You answered all {$total} ".($total === 1 ? 'question' : 'questions')." correctly on \"{$concept}\" ({$this->formatPercent($accuracy)}%).";
        }

        $missed = $total - $correct;

        return "You answered {$correct} of {$total} ".($total === 1 ? 'question' : 'questions')." correctly on \"{$concept}\" ({$this->formatPercent($accuracy)}%), missing {$missed}. The misconceptions below explain what went wrong.";
    }

    protected function summary(float $accuracy, float $passing, bool $passed): string
    {
        if ($passed && $accuracy >= 100) {
            return 'Perfect result - no recovery needed. This concept is securely mastered.';
        }

        if ($accuracy >= self::GOOD_ACCURACY) {
            return 'Solid result. Review the few missed questions below to make this concept secure.';
        }

        if ($passed) {
            return 'You passed, but a couple of answers missed the mark. The recovery path below will close the gaps.';
        }

        return "The result fell below the required {$this->formatPercent($passing)}%. Follow the recovery path below before retaking the quiz.";
    }

    protected function formatPercent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.');
    }
}
