<?php

namespace App\Exports;

use App\Models\Course;
use App\Services\GradebookService;

/**
 * The gradebook as sheets.
 *
 * Built from `GradebookService::courseGradebook()` -- the same array the page
 * renders -- so an export cannot report a different course grade, a different
 * class average, or a different set of dropped grades than the instructor is
 * looking at. Recomputing any of it here would be the one way this could drift.
 */
class GradebookExport
{
    public function __construct(private readonly GradebookService $gradebook) {}

    /**
     * The grid: one row per student, one column per assessment.
     *
     * @return array<int, array{name: string, rows: array}>
     */
    public function grid(Course $course): array
    {
        $data = $this->gradebook->courseGradebook($course);
        $assessments = $data['assessments'];
        $categories = collect($data['categories'])->keyBy('key');

        $rows = [];

        $header = [
            ['value' => 'Student', 'style' => 'header'],
            ['value' => 'Email', 'style' => 'header'],
        ];

        foreach ($assessments as $assessment) {
            $category = $categories->get($assessment['category_key']);
            $label = $category === null ? $assessment['title'] : $category['name'].' · '.$assessment['title'];

            $header[] = ['value' => $label, 'style' => 'header'];
        }

        $header[] = ['value' => 'Course grade', 'style' => 'header'];
        $header[] = ['value' => 'Adjustments', 'style' => 'header'];

        $rows = [
            [['value' => $course->title, 'style' => 'header']],
            [$this->exportedAt()],
            [],
            $header,
        ];

        foreach ($data['students'] as $student) {
            $row = [
                $student['name'],
                $student['email'],
            ];

            $adjustments = [];

            foreach ($assessments as $assessment) {
                $cell = $student['cells'][$assessment['key']] ?? null;

                $row[] = match (true) {
                    $cell === null => null,
                    $cell['dropped'] => ['value' => null, 'style' => 'excluded'],
                    default => ['value' => $this->number($cell['percentage']), 'style' => $cell['overridden'] ? 'adjusted' : null],
                };

                if ($cell !== null && ($cell['dropped'] || $cell['overridden'])) {
                    $adjustments[] = $assessment['title'].': '.$this->describeCell($cell);
                }
            }

            $row[] = $this->number($student['course_percentage']);
            $row[] = implode('; ', $adjustments);

            $rows[] = $row;
        }

        $rows[] = [];
        $rows[] = ['A blank cell means the grade does not exist, or was excluded from the course grade.'];
        $rows[] = ['An adjusted cell holds the score the course reports; the recorded score is unchanged.'];
        $rows[] = ['The adjustment history lists who changed a grade, when, and why.'];

        return [
            [
                'name' => 'Gradebook',
                'rows' => $this->align($rows, count($assessments) + 4),
            ],
        ];
    }

    /**
     * The course's trail of grade decisions.
     *
     * @return array<int, array{name: string, rows: array}>
     */
    public function adjustments(Course $course, ?int $limit = null): array
    {
        $rows = [[
            ['value' => 'When', 'style' => 'header'],
            ['value' => 'Student', 'style' => 'header'],
            ['value' => 'Assessment', 'style' => 'header'],
            ['value' => 'Change', 'style' => 'header'],
            ['value' => 'Score before', 'style' => 'header'],
            ['value' => 'Score after', 'style' => 'header'],
            ['value' => 'Counted', 'style' => 'header'],
            ['value' => 'Note', 'style' => 'header'],
            ['value' => 'By', 'style' => 'header'],
        ]];

        foreach ($this->gradebook->recentAdjustments($course, $limit) as $entry) {
            $rows[] = [
                $entry['adjusted_at'],
                $entry['student']['name'],
                $entry['assessment']['title'],
                $entry['summary'],
                $this->scoreText($entry['from']),
                $this->scoreText([
                    'score' => $entry['score'],
                    'max_score' => $entry['max_score'],
                    'percentage' => $entry['percentage'],
                ]),
                $entry['dropped'] ? 'no' : 'yes',
                $entry['note'] ?? '',
                $entry['adjuster']['name'] ?? 'Unknown',
            ];
        }

        return [['name' => 'Adjustments', 'rows' => $rows]];
    }

    /**
     * @param  array{score: float|null, max_score: float|null, percentage: float|null}  $state
     */
    private function scoreText(array $state): string
    {
        if ($state['score'] === null) {
            return '';
        }

        return sprintf('%s of %s (%s%%)', $this->number($state['score']), $this->number($state['max_score']), $this->number($state['percentage']));
    }

    /**
     * @param  array{dropped: bool, overridden: bool, recorded_percentage: float}  $cell
     */
    private function describeCell(array $cell): string
    {
        if ($cell['dropped']) {
            // The percentage, not the raw score: "was 5%" next to a blank cell
            // reads as a mark of five percent, and the student earned fifty.
            return sprintf('excluded from the course grade (was %s%%)', $this->number($cell['recorded_percentage']));
        }

        return sprintf('score adjusted to %s%%', $this->number($cell['percentage']));
    }

    /**
     * Pad or leave every row to the same width, so a blank leading cell does not
     * shift a whole student across the columns.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<int, array<int, mixed>>
     */
    private function align(array $rows, int $width): array
    {
        return array_map(function (array $row) use ($width): array {
            $row = array_values($row);

            while (count($row) < $width) {
                $row[] = null;
            }

            return array_slice($row, 0, $width);
        }, $rows);
    }

    private function number(float|int|null $value): float|int|null
    {
        return $value === null ? null : round($value, 2);
    }

    private function exportedAt(): string
    {
        return 'Exported '.now()->format('j M Y, H:i');
    }
}
