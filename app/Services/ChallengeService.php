<?php

namespace App\Services;

use App\Models\QuizQuestion;
use App\Models\User;
use App\QuizQuestionType;
use Illuminate\Support\Collection;

/**
 * Builds a short practice challenge from a concept the student has already
 * mastered (real questions - never fabricated). Grading happens in memory and
 * never touches official quiz attempts or grades.
 */
class ChallengeService
{
    public const QUESTION_COUNT = 5;

    /**
     * @return array<string, mixed>|null
     */
    public function build(User $student): ?array
    {
        $mastery = (new MasteryCalculator)->masteryFor($student);

        $concept = $this->pickMasteredConcept($mastery['courses']);

        if ($concept === null) {
            return null;
        }

        $questions = $this->questionsForSection((int) $concept['section_id']);

        if ($questions->isEmpty()) {
            return null;
        }

        return [
            'concept' => $concept['concept'],
            'course' => $concept['course'],
            'mastery_percent' => $concept['mastery_percent'],
            'total_questions' => min(self::QUESTION_COUNT, $questions->count()),
            'questions' => $questions->take(self::QUESTION_COUNT)->map(fn (QuizQuestion $question) => [
                'id' => $question->id,
                'question_text' => $question->question_text,
                'points' => (float) $question->points,
                'options' => $question->options->map(fn ($option) => [
                    'id' => $option->id,
                    'option_text' => $option->option_text,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $courses
     * @return array<string, mixed>|null
     */
    private function pickMasteredConcept(array $courses): ?array
    {
        $candidates = collect();

        foreach ($courses as $course) {
            foreach ($course['concepts'] as $concept) {
                if ($concept['status'] !== MasteryCalculator::STATUS_MASTERED) {
                    continue;
                }

                $candidates->push($concept);
            }
        }

        if ($candidates->isEmpty()) {
            return null;
        }

        return $candidates->sortByDesc('mastery_percent')->first();
    }

    /**
     * @return Collection<int, QuizQuestion>
     */
    private function questionsForSection(int $sectionId): Collection
    {
        return QuizQuestion::query()
            ->whereIn('type', [QuizQuestionType::MultipleChoice->value, QuizQuestionType::TrueFalse->value])
            ->whereHas('quiz.lesson', fn ($query) => $query->where('section_id', $sectionId))
            ->with(['options' => fn ($query) => $query->orderBy('sort_order')])
            ->inRandomOrder()
            ->limit(self::QUESTION_COUNT)
            ->get();
    }

    /**
     * Grade a submitted challenge against the real questions. Read-only.
     *
     * @param  array<int, int>  $questionIds
     * @param  array<int, int>  $answers  keyed by question id
     * @return array<string, mixed>
     */
    public function grade(array $questionIds, array $answers): array
    {
        $questions = QuizQuestion::query()
            ->whereIn('id', $questionIds)
            ->with(['options' => fn ($query) => $query->orderBy('sort_order')])
            ->get()
            ->sortBy(fn (QuizQuestion $question) => array_search($question->id, $questionIds, true))
            ->values();

        $correct = 0;
        $results = [];

        foreach ($questions as $question) {
            $chosenId = isset($answers[$question->id]) ? (int) $answers[$question->id] : null;
            $chosen = $chosenId !== null
                ? $question->options->first(fn ($option) => (int) $option->id === $chosenId)
                : null;
            $correctOption = $question->options->first(fn ($option) => (bool) $option->is_correct);

            $isCorrect = $chosen !== null && (bool) $chosen->is_correct;

            if ($isCorrect) {
                $correct++;
            }

            $results[] = [
                'id' => $question->id,
                'question_text' => $question->question_text,
                'is_correct' => $isCorrect,
                'submitted_answer_text' => $chosen?->option_text,
                'correct_answer_text' => $correctOption?->option_text,
                'explanation' => $chosen !== null && ! $chosen->is_correct
                    ? ($chosen->explanation ?: ($correctOption?->explanation ?? null))
                    : ($correctOption?->explanation ?? null),
            ];
        }

        $total = $questions->count();
        $percent = $total === 0 ? 0 : round($correct / $total * 100, 1);

        return [
            'score' => $correct,
            'total' => $total,
            'correct_count' => $correct,
            'percent' => $percent,
            'summary' => $this->summary($correct, $total),
            'questions' => $results,
        ];
    }

    protected function summary(int $correct, int $total): string
    {
        if ($total === 0) {
            return 'There were no questions to grade.';
        }

        $ratio = $correct / $total;

        return match (true) {
            $ratio >= 0.9 => "Excellent - {$correct} of {$total} correct. This concept is holding up well.",
            $ratio >= 0.75 => "Good - {$correct} of {$total} correct. Review the missed questions to keep mastery solid.",
            $ratio >= 0.6 => "Holding - {$correct} of {$total} correct. The missed questions point at what to revisit.",
            default => "Worth a reset - {$correct} of {$total} correct. Reopen the concept from your learning map before the next challenge.",
        };
    }
}
