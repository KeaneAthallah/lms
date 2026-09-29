<?php

namespace App\Support\Grading;

use App\Models\QuizQuestion;

/**
 * Grades a free-text `short_answer`.
 *
 * Two modes, chosen by the presence of `settings.rubric`:
 *
 * - default (no rubric) -- the stored answer is compared case-insensitively
 *   against the option marked correct after trimming.
 * - rubric (`settings.rubric` set) -- the answer is scored against an ordered
 *   list of criteria. Each criterion lists the whole terms that signal it, so
 *   an answer that is wrong as a whole but contains the right ideas earns
 *   partial credit. A criterion matches when *any* of its terms appears as a
 *   whole word, and the earned total is capped at the question's points.
 *
 * The keywords are the scoring rule, so they are the same secret the correct
 * option is for the exact mode: `settingsForStudent()` discloses the
 * criteria's labels and points (so a student knows what is assessed) but never
 * the terms that trigger a match.
 */
class ShortAnswerGrader implements Grader
{
    use NegativeMarking;

    public function grade(QuizQuestion $question, mixed $submitted): float
    {
        if ($submitted === null || $submitted === '') {
            return 0.0;
        }

        $rubric = $question->settings['rubric'] ?? [];

        if ($rubric !== []) {
            return $this->rubricGrade($question, (string) $submitted);
        }

        $correct = $question->options->firstWhere('is_correct', true);

        if (! $correct) {
            return 0.0;
        }

        return $this->normalize((string) $submitted) === $this->normalize($correct->option_text)
            ? (float) $question->points
            : 0.0;
    }

    public function serialize(mixed $submitted): string
    {
        return (string) $submitted;
    }

    public function decode(?string $stored): mixed
    {
        return $stored;
    }

    public function settingsForStudent(QuizQuestion $question): array
    {
        $settings = $this->negativeMarkingForStudent($question);

        $criteria = $question->settings['rubric'] ?? [];

        if ($criteria !== []) {
            $settings['rubric'] = array_map(
                fn (array $criterion): array => [
                    'label' => (string) ($criterion['label'] ?? ''),
                    'points' => (float) ($criterion['points'] ?? 0),
                ],
                array_values($criteria),
            );
        }

        return $settings;
    }

    /**
     * Award the points of every criterion whose terms appear in the answer,
     * capped at the question's points so a rubric cannot out-score the
     * question it grades.
     */
    private function rubricGrade(QuizQuestion $question, string $answer): float
    {
        $earned = 0.0;

        foreach ($question->settings['rubric'] as $criterion) {
            $keywords = $criterion['keywords'] ?? [];

            foreach ($keywords as $keyword) {
                if (trim((string) $keyword) === '') {
                    continue;
                }

                if ($this->containsWord($answer, (string) $keyword)) {
                    $earned += (float) ($criterion['points'] ?? 0);
                    break;
                }
            }
        }

        return round(min((float) $question->points, $earned), 2);
    }

    /**
     * Whether the answer contains the term as a whole word.
     *
     * A substring check would match "rate" inside "separate" and hand credit
     * for a term the answer never used, which is exactly the false positive
     * keyword scoring has to avoid.
     */
    private function containsWord(string $answer, string $keyword): bool
    {
        return preg_match('/\b'.preg_quote($keyword, '/').'\b/i', $answer) === 1;
    }

    private function normalize(string $value): string
    {
        return TextComparison::normalize($value);
    }
}
