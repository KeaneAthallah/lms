<?php

namespace App\Services;

use App\EnrollmentStatus;
use App\GradeAdjustmentAction;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\GradeAdjustment;
use App\Models\GradebookCategory;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Aggregates the per-student grade snapshot for one course.
 *
 * The gradebook is read-only: the ledger row is written wherever the assessment
 * is graded (quiz attempt or assignment submission) and this service only groups
 * and averages it. A quiz that allows retakes produces one ledger row per graded
 * attempt, so a student's cell is their best attempt, exactly like
 * `Quiz::bestAttemptFor()`. An assignment produces one row per student-submission,
 * so its cell is that row.
 *
 * Assessments may be grouped into weighted categories. The course percentage is
 * the weighted mean of the buckets the student has a grade in: within a bucket
 * their score is the mean of its graded cells, that mean is multiplied by the
 * bucket's weight, and the weighted sum is divided by the weight actually
 * applied. An untaken assessment is a dash that counts for nothing (not a zero,
 * so it does not drag a bucket - or the course grade - down). A course with no
 * categories is a single uncategorized bucket of weight 1, which reproduces the
 * simple mean exactly.
 *
 * Cells report the grade as the course stands, not as the grader recorded it: an
 * instructor's adjustment (a manual score, or a grade excluded from the average)
 * takes precedence over the ledger row. A dropped cell is still shown -- the
 * instructor has to see that the grade exists and why it is not counting -- but
 * it counts for nothing at all: not in the student's total, not in the bucket
 * subtotal, and not in the class average, since a grade declared not real should
 * not shape the column either.
 */
class GradebookService
{
    /** The bucket an assessment with no category lands in. */
    public const UNCATEGORIZED = 'uncategorized';

    public function courseGradebook(Course $course): array
    {
        $assessments = $this->assessments($course);

        $categories = $this->categories($course, $assessments);

        $enrollments = Enrollment::where('course_id', $course->id)
            ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Completed])
            ->with('student:id,name,email')
            // Students as rows, in a stable order. The correlated subquery sorts
            // without joining users into the enrollments query scope.
            ->orderBy(User::select('name')->whereColumn('users.id', 'enrollments.student_id'))
            ->get();

        $grades = Grade::where('course_id', $course->id)
            ->whereIn('source_type', [QuizAttempt::class, AssignmentSubmission::class])
            // The adjustment in force and who set it, for every cell at once
            // rather than a query per cell when a cell is read.
            ->with(['source', 'latestAdjustment.adjuster:id,name'])
            ->get();

        [$bestQuizByStudent, $assignmentByStudent] = $this->indexedByAssessment($grades);

        $bucketKeys = collect($categories)->pluck('key')->all();
        $weights = collect($categories)->mapWithKeys(fn (array $category): array => [$category['key'] => $category['weight']])->all();

        $students = $enrollments->map(fn (Enrollment $enrollment): array => $this->studentRow(
            $enrollment,
            $assessments,
            $bucketKeys,
            $weights,
            $bestQuizByStudent,
            $assignmentByStudent,
        ));

        $averages = $this->assessmentAverages($assessments->pluck('key')->all(), $students);

        $categories = $this->withAverages($categories, $students);

        return [
            'course' => [
                'id' => $course->id,
                'title' => $course->title,
                'slug' => $course->slug,
            ],
            'categories' => $categories,
            'assessments' => $assessments->map(fn (array $assessment): array => [
                'key' => $assessment['key'],
                'type' => $assessment['type'],
                'id' => $assessment['id'],
                'title' => $assessment['title'],
                'category_id' => $assessment['category_id'],
                'category_key' => $assessment['category_key'],
                'average' => $averages[$assessment['key']] ?? null,
            ])->values()->all(),
            'students' => $students->values()->all(),
        ];
    }

    /**
     * Every assessment column, quizzes and assignments together, grouped by
     * gradebook category (in the category's order, uncategorized last) and by
     * title within a group. Each carries its category so a column maps back to
     * its quiz or assignment, its bucket, and its id without parsing the key.
     *
     * @return \Illuminate\Support\Collection<int, array{
     *     key: string,
     *     type: string,
     *     id: int,
     *     title: string,
     *     category_id: int|null,
     *     category_key: string,
     *     group: int,
     * }>
     */
    private function assessments(Course $course): \Illuminate\Support\Collection
    {
        $categoryPositions = $course->gradebookCategories
            ->mapWithKeys(fn (GradebookCategory $category, int $index): array => [$category->id => $index]);
        $uncategorized = $categoryPositions->count();

        $toColumn = function (string $type, int $id, string $title, ?int $categoryId) use ($categoryPositions, $uncategorized): array {
            $position = $categoryId !== null
                ? $categoryPositions->get($categoryId, $uncategorized)
                : $uncategorized;

            return [
                'key' => "{$type}-{$id}",
                'type' => $type,
                'id' => $id,
                'title' => $title,
                'category_id' => $categoryId,
                'category_key' => $categoryId !== null
                    ? "category-{$categoryId}"
                    : self::UNCATEGORIZED,
                'group' => $position,
            ];
        };

        return collect()
            ->concat($course->quizzes()->get(['id', 'title', 'category_id'])->map(
                fn ($quiz): array => $toColumn('quiz', (int) $quiz->id, $quiz->title, $quiz->category_id !== null ? (int) $quiz->category_id : null),
            ))
            ->concat($course->assignments()->get(['id', 'title', 'category_id'])->map(
                fn ($assignment): array => $toColumn('assignment', (int) $assignment->id, $assignment->title, $assignment->category_id !== null ? (int) $assignment->category_id : null),
            ))
            ->sortBy(fn (array $assessment): array => [$assessment['group'], strtolower($assessment['title'])])
            ->values();
    }

    /**
     * The course's gradebook categories (plus an implicit uncategorized bucket,
     * only when an assessment actually sits outside every defined category), each
     * with the number of columns it holds.
     *
     * @param  \Illuminate\Support\Collection<int, array{category_key: string}>  $assessments
     * @return array<int, array{key: string, id: int|null, name: string, weight: float, assessment_count: int}>
     */
    private function categories(Course $course, \Illuminate\Support\Collection $assessments): array
    {
        $columnsPerKey = $assessments->groupBy('category_key')->map->count()->all();

        $categories = $course->gradebookCategories->map(fn (GradebookCategory $category): array => [
            'key' => "category-{$category->id}",
            'id' => (int) $category->id,
            'name' => $category->name,
            'weight' => (float) $category->weight,
            'assessment_count' => $columnsPerKey["category-{$category->id}"] ?? 0,
        ])->all();

        if (isset($columnsPerKey[self::UNCATEGORIZED])) {
            $categories[] = [
                'key' => self::UNCATEGORIZED,
                'id' => null,
                'name' => 'Uncategorized',
                'weight' => 1.0,
                'assessment_count' => $columnsPerKey[self::UNCATEGORIZED],
            ];
        }

        return $categories;
    }

    /**
     * Group the course's graded ledger rows by the assessment they belong to.
     *
     * The underlying quiz or submission is reached through the polymorphic
     * `source`, because a grade's `source_id` is an attempt or a submission id,
     * not the quiz or assignment that names the column.
     *
     * @param  Collection<int, Grade>  $grades
     * @return array{
     *     0: array<int, array<int, Grade>>,
     *     1: array<int, array<int, Grade>>,
     * }
     */
    private function indexedByAssessment(Collection $grades): array
    {
        $bestQuizByStudent = [];
        $assignmentByStudent = [];

        foreach ($grades as $grade) {
            if ($grade->source_type === QuizAttempt::class) {
                $attempt = $grade->source;

                // A ledger row whose source was deleted is orphaned: show nothing
                // rather than a column that never resolves.
                if (! $attempt || ! $attempt->quiz_id) {
                    continue;
                }

                $quizId = (int) $attempt->quiz_id;
                $current = $bestQuizByStudent[$quizId][$grade->student_id] ?? null;

                if ($current === null || $this->rank($grade) > $this->rank($current)) {
                    $bestQuizByStudent[$quizId][$grade->student_id] = $grade;
                }
            } elseif ($grade->source_type === AssignmentSubmission::class) {
                $submission = $grade->source;

                if (! $submission || ! $submission->assignment_id) {
                    continue;
                }

                $assignmentByStudent[(int) $submission->assignment_id][$grade->student_id] = $grade;
            }
        }

        return [$bestQuizByStudent, $assignmentByStudent];
    }

    /**
     * @param  array<int, string>  $bucketKeys
     * @param  array<string, float>  $weights
     * @param  array<int, array<int, Grade>>  $bestQuizByStudent
     * @param  array<int, array<int, Grade>>  $assignmentByStudent
     * @return array{
     *     id: int,
     *     name: string,
     *     email: string,
     *     graded_count: int,
     *     course_percentage: float|null,
     *     category_percentages: array<string, float|null>,
     *     cells: array<string, array{
     *         grade_id: int,
     *         score: float,
     *         max_score: float,
     *         percentage: float,
     *         dropped: bool,
     *         overridden: bool,
     *         recorded_score: float,
     *         recorded_max_score: float,
     *         recorded_percentage: float,
     *         adjusted_by: string|null,
     *         adjusted_at: string|null,
     *         note: string|null,
     *     }|null>,
     * }
     */
    private function studentRow(Enrollment $enrollment, \Illuminate\Support\Collection $assessments, array $bucketKeys, array $weights, array $bestQuizByStudent, array $assignmentByStudent): array
    {
        $cells = [];
        $graded = 0;
        $bucketTotals = [];
        $bucketCounts = [];

        foreach ($assessments as $assessment) {
            $grade = $assessment['type'] === 'quiz'
                ? ($bestQuizByStudent[$assessment['id']][$enrollment->student_id] ?? null)
                : ($assignmentByStudent[$assessment['id']][$enrollment->student_id] ?? null);

            if ($grade === null) {
                $cells[$assessment['key']] = null;

                continue;
            }

            $adjustment = $grade->latestAdjustment;

            // Reported with the recorded score alongside it, so an overridden
            // cell can show what the grader awarded without the two being
            // mistaken for one another.
            $cells[$assessment['key']] = [
                'grade_id' => (int) $grade->id,
                'score' => $grade->effectiveScore(),
                'max_score' => $grade->effectiveMaxScore(),
                'percentage' => $grade->effectivePercentage(),
                'dropped' => $grade->isDropped(),
                'overridden' => $grade->isOverridden(),
                'recorded_score' => (float) $grade->score,
                'recorded_max_score' => (float) $grade->max_score,
                'recorded_percentage' => (float) $grade->percentage,
                'adjusted_by' => $adjustment?->adjuster?->name,
                'adjusted_at' => $adjustment?->adjusted_at?->toIso8601String(),
                'note' => $adjustment?->note,
            ];

            if ($grade->isDropped()) {
                continue;
            }

            $graded++;

            $bucketTotals[$assessment['category_key']] = ($bucketTotals[$assessment['category_key']] ?? 0) + $grade->effectivePercentage();
            $bucketCounts[$assessment['category_key']] = ($bucketCounts[$assessment['category_key']] ?? 0) + 1;
        }

        // A 0% is a real grade; an empty bucket is not. Unset buckets stay null,
        // so the frontend can render them as dashes rather than zeroes.
        $bucketPercentages = [];
        foreach ($bucketKeys as $key) {
            $bucketPercentages[$key] = isset($bucketCounts[$key])
                ? round($bucketTotals[$key] / $bucketCounts[$key], 2)
                : null;
        }

        // The weighted mean of the buckets that actually have a grade, over the
        // weight of just those buckets: a bucket the student never touched does
        // not drag the course grade (or the denominator) down.
        $numerator = 0.0;
        $denominator = 0.0;

        foreach ($bucketPercentages as $key => $percentage) {
            if ($percentage === null) {
                continue;
            }

            $weight = $weights[$key];
            $numerator += $weight * $percentage;
            $denominator += $weight;
        }

        $student = $enrollment->student;

        return [
            'id' => $student->id,
            'name' => $student->name,
            'email' => $student->email,
            'graded_count' => $graded,
            'course_percentage' => $graded > 0 && $denominator > 0 ? round($numerator / $denominator, 2) : null,
            'category_percentages' => $bucketPercentages,
            'cells' => $cells,
        ];
    }

    /**
     * The percentage one cell contributes to an average, or null when it
     * contributes nothing.
     *
     * Two different absences, and both have to leave the average alone: a cell
     * with no grade (never sat it) and a cell whose grade was dropped (the
     * instructor ruled it out). A 0% is a real grade and is kept.
     *
     * @param  array{cells: array<string, array{percentage: float, dropped: bool}|null>}  $student
     */
    private function countedPercentage(array $student, string $key): ?float
    {
        $cell = $student['cells'][$key] ?? null;

        if ($cell === null || $cell['dropped']) {
            return null;
        }

        return (float) $cell['percentage'];
    }

    /**
     * The class average of a column: the mean over the students who have a grade
     * in it, so a column is not dragged down by the students who have not sat it.
     *
     * @param  array<int, string>  $keys
     * @param  \Illuminate\Support\Collection<int, array{cells: array<string, array{percentage: float, dropped: bool}|null>}>  $students
     * @return array<string, float>
     */
    private function assessmentAverages(array $keys, \Illuminate\Support\Collection $students): array
    {
        $averages = [];

        foreach ($keys as $key) {
            // A 0% is a real grade; an ungraded or dropped cell is absent. Only
            // the latter may be dropped, so the filter keeps zeroes.
            $percentages = $students
                ->map(fn (array $student): ?float => $this->countedPercentage($student, $key))
                ->filter(fn (?float $percentage): bool => $percentage !== null)
                ->values();

            if ($percentages->isNotEmpty()) {
                $averages[$key] = round($percentages->avg(), 2);
            }
        }

        return $averages;
    }

    /**
     * Add the class average of each bucket, over the students who have a grade
     * in it (the same rule as a single column).
     *
     * @param  array<int, array{key: string}>  $categories
     * @param  \Illuminate\Support\Collection<int, array{category_percentages: array<string, float|null>}>  $students
     * @return array<int, array{key: string, id: int|null, name: string, weight: float, assessment_count: int, average: float|null}>
     */
    private function withAverages(array $categories, \Illuminate\Support\Collection $students): array
    {
        return array_map(function (array $category) use ($students): array {
            $percentages = $students
                ->map(fn (array $student): ?float => $student['category_percentages'][$category['key']] ?? null)
                ->filter(fn (?float $percentage): bool => $percentage !== null)
                ->values();

            return [
                ...$category,
                'average' => $percentages->isNotEmpty() ? round($percentages->avg(), 2) : null,
            ];
        }, $categories);
    }

    /**
     * The course's trail of grade decisions, newest first.
     *
     * The assessment a row refers to is reached through the grade's polymorphic
     * source, so the titles are resolved in two batched queries rather than one
     * per row: a trail is read as a list of "who changed what, and when", and
     * loading it must not cost a query per entry.
     *
     * The page shows the most recent decisions, so it caps the list; an export is
     * the whole record and asks for it all.
     *
     * @return array<int, array{
     *     id: int,
     *     action: string,
     *     summary: string,
     *     from: array{dropped: bool, overridden: bool, score: float|null, max_score: float|null, percentage: float|null},
     *     dropped: bool,
     *     score: float|null,
     *     max_score: float|null,
     *     percentage: float|null,
     *     note: string|null,
     *     adjusted_at: string|null,
     *     adjuster: array{id: int, name: string}|null,
     *     student: array{id: int, name: string, email: string},
     *     assessment: array{key: string, title: string, type: string},
     * }>
     */
    public function recentAdjustments(Course $course, ?int $limit = 50): array
    {
        $query = GradeAdjustment::where('course_id', $course->id)
            ->with(['adjuster:id,name', 'student:id,name,email', 'grade.source'])
            ->orderByDesc('adjusted_at')
            ->orderByDesc('id');

        $adjustments = $limit === null
            ? $query->get()
            : $query->limit($limit)->get();

        $titles = $this->assessmentTitles($adjustments->pluck('grade'));
        $previous = $this->previousStates($adjustments);
        $shown = $adjustments->keyBy('id');

        return $adjustments->map(function (GradeAdjustment $adjustment) use ($titles, $previous): array {
            $grade = $adjustment->grade;
            $type = $grade?->type ?? 'quiz';
            $source = $grade?->source;
            $assessmentId = $type === 'quiz' ? $source?->quiz_id : $source?->assignment_id;
            $key = $assessmentId ? "{$type}-{$assessmentId}" : '';
            $before = $previous[$adjustment->id];

            return [
                'id' => (int) $adjustment->id,
                'action' => $adjustment->action->value,
                'summary' => $this->describeAdjustment($adjustment, $before),
                'from' => [
                    'dropped' => $before['dropped'],
                    'overridden' => $before['overridden'],
                    'score' => $before['score'],
                    'max_score' => $before['max_score'],
                    'percentage' => $before['percentage'],
                ],
                'dropped' => (bool) $adjustment->dropped,
                'score' => $adjustment->hasOverride() ? (float) $adjustment->score : null,
                'max_score' => $adjustment->hasOverride() ? (float) $adjustment->max_score : null,
                'percentage' => $adjustment->hasOverride() ? (float) $adjustment->percentage : null,
                'note' => $adjustment->note,
                'adjusted_at' => $adjustment->adjusted_at?->toIso8601String(),
                'adjuster' => $adjustment->adjuster ? [
                    'id' => (int) $adjustment->adjuster->id,
                    'name' => $adjustment->adjuster->name,
                ] : null,
                'student' => [
                    'id' => (int) $adjustment->student_id,
                    'name' => $adjustment->student?->name ?? 'Unknown',
                    'email' => $adjustment->student?->email ?? '',
                ],
                'assessment' => [
                    'key' => $key,
                    'title' => $key === '' ? 'Deleted assessment' : ($titles[$key] ?? 'Deleted assessment'),
                    'type' => $type,
                ],
            ];
        })->values()->all();
    }

    /**
     * What each grade read immediately before each adjustment on the page, keyed
     * by adjustment id.
     *
     * A row records the state after its action, so a trail that said only "now
     * 80%" would leave a contested mark unanswerable -- the question is always
     * what it was before. The state before a row is the one below it in the
     * grade's own chain, which is found in one query over the grades on the page;
     * for the first adjustment on a grade there is no row below it, and the state
     * before that is simply the recorded grade.
     *
     * @param  \Illuminate\Support\Collection<int, GradeAdjustment>  $adjustments
     * @return array<int, array{dropped: bool, overridden: bool, score: float|null, max_score: float|null, percentage: float|null}>
     */
    private function previousStates(\Illuminate\Support\Collection $adjustments): array
    {
        if ($adjustments->isEmpty()) {
            return [];
        }

        $shown = $adjustments->keyBy('id');

        $history = GradeAdjustment::whereIn('grade_id', $adjustments->pluck('grade_id')->unique())
            ->orderBy('id')
            ->get(['id', 'grade_id', 'dropped', 'score', 'max_score', 'percentage']);

        $previous = [];
        $older = [];

        foreach ($history as $row) {
            if ($shown->has($row->id)) {
                $previous[$row->id] = $older[$row->grade_id] ?? null;
            }

            $older[$row->grade_id] = $row;
        }

        return $adjustments
            ->mapWithKeys(fn (GradeAdjustment $adjustment): array => [
                $adjustment->id => $this->adjustmentState($previous[$adjustment->id] ?? null, $adjustment->grade),
            ])
            ->all();
    }

    /**
     * @return array{dropped: bool, overridden: bool, score: float|null, max_score: float|null, percentage: float|null}
     */
    private function adjustmentState(?GradeAdjustment $row, ?Grade $grade): array
    {
        if ($row === null) {
            // No adjustment preceded this one, so the grade read exactly as the
            // grader recorded it.
            return [
                'dropped' => false,
                'overridden' => false,
                'score' => $grade === null ? null : (float) $grade->score,
                'max_score' => $grade === null ? null : (float) $grade->max_score,
                'percentage' => $grade === null ? null : (float) $grade->percentage,
            ];
        }

        $overridden = $row->hasOverride();

        return [
            'dropped' => (bool) $row->dropped,
            'overridden' => $overridden,
            'score' => $overridden ? (float) $row->score : null,
            'max_score' => $overridden ? (float) $row->max_score : null,
            'percentage' => $overridden ? (float) $row->percentage : null,
        ];
    }

    /**
     * The change in words, phrased once here so the history and a later export
     * read the same way.
     *
     * @param  array{dropped: bool, overridden: bool, score: float|null, max_score: float|null, percentage: float|null}  $before
     */
    private function describeAdjustment(GradeAdjustment $adjustment, array $before): string
    {
        $to = $adjustment->hasOverride() ? $this->describeNumbers($adjustment->score, $adjustment->max_score, $adjustment->percentage) : null;
        $from = $before['score'] !== null ? $this->describeNumbers($before['score'], $before['max_score'], $before['percentage']) : null;
        $wasDropped = $before['dropped'];

        return match ($adjustment->action) {
            GradeAdjustmentAction::Override => $from ? "Score changed from {$from} to {$to}" : "Score set to {$to}",
            GradeAdjustmentAction::ClearOverride => $from
                ? "Score override removed, back to the recorded {$from} instead of the adjusted score"
                : 'Score override removed',
            GradeAdjustmentAction::Drop => $adjustment->hasOverride()
                ? "Excluded from the course grade, keeping the adjusted {$to}"
                : 'Excluded from the course grade',
            GradeAdjustmentAction::Restore => $wasDropped ? 'Included in the course grade again' : 'Marked as counting towards the course grade',
            GradeAdjustmentAction::Annotate => $from && $to ? "Note added, still reported as {$to}" : 'Note added',
        };
    }

    /**
     * "8 of 10 (80%)" -- without the two decimal places the column's own scale
     * carries, which read as false precision in a sentence about a mark.
     */
    private function describeNumbers(float $score, float $maxScore, float $percentage): string
    {
        return sprintf(
            '%s of %s (%s%%)',
            $this->trimmedNumber($score),
            $this->trimmedNumber($maxScore),
            $this->trimmedNumber($percentage)
        );
    }

    private function trimmedNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * Assessment titles for the grades in a trail, keyed the way a gradebook
     * column is keyed (`quiz-12`), in one query per assessment kind.
     *
     * @param  \Illuminate\Support\Collection<int, Grade>  $grades
     * @return array<string, string>
     */
    private function assessmentTitles(\Illuminate\Support\Collection $grades): array
    {
        $quizIds = [];
        $assignmentIds = [];

        foreach ($grades as $grade) {
            $source = $grade?->source;

            if (! $source) {
                continue;
            }

            if ($grade->type === 'quiz' && $source->quiz_id) {
                $quizIds[] = (int) $source->quiz_id;
            }

            if ($grade->type === 'assignment' && $source->assignment_id) {
                $assignmentIds[] = (int) $source->assignment_id;
            }
        }

        $titles = [];

        foreach (Quiz::whereIn('id', $quizIds)->pluck('title', 'id') as $id => $title) {
            $titles["quiz-{$id}"] = $title;
        }

        foreach (Assignment::whereIn('id', $assignmentIds)->pluck('title', 'id') as $id => $title) {
            $titles["assignment-{$id}"] = $title;
        }

        return $titles;
    }

    /**
     * The higher of percentage-first, absolute-score-second, so a better result
     * on an equal-percentage-but-larger-score attempt wins the cell.
     */
    private function rank(Grade $grade): float
    {
        return (float) $grade->percentage * 1000000 + (float) $grade->score;
    }
}
