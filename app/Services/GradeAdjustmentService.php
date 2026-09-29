<?php

namespace App\Services;

use App\GradeAdjustmentAction;
use App\Models\Grade;
use App\Models\GradeAdjustment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies an instructor's decision about a grade.
 *
 * The ledger row in `grades` is what a grader produced and is never rewritten:
 * it is the evidence a contested mark is argued from. An adjustment is a
 * separate, append-only record of what the course should *report* instead --
 * a manual score, a grade excluded from the average, or a note explaining
 * either -- so the two can disagree on purpose and the original survives.
 *
 * Each adjustment row is a full snapshot of the state that action put in force,
 * so a later regrade of the underlying attempt cannot silently erase a
 * decision an instructor made on purpose, and the trail reads by pairing
 * consecutive rows.
 */
class GradeAdjustmentService
{
    /**
     * Put the submitted fields in force on a grade.
     *
     * Every field is "set it to this": an absent field keeps its current value,
     * an explicit null clears it. That makes the request idempotent and lets a
     * dialog send only what the instructor touched.
     *
     * @param  array{dropped?: bool, score?: float|null, max_score?: float|null, note?: string|null}  $input
     * @return array{adjustment: GradeAdjustment, changed: bool} the state now in force
     */
    public function apply(Grade $grade, array $input, User $actor): array
    {
        return DB::transaction(function () use ($grade, $input, $actor): array {
            $current = $grade->adjustmentState();
            $next = $this->nextState($grade, $current, $input);

            // Re-saving an untouched dialog is not a decision, and a trail full
            // of no-op rows would bury the ones that are.
            if ($this->isUnchanged($current, $next)) {
                return ['adjustment' => $grade->latestAdjustment, 'changed' => false];
            }

            $adjustment = GradeAdjustment::create([
                'grade_id' => $grade->id,
                'student_id' => $grade->student_id,
                'course_id' => $grade->course_id,
                'action' => $this->actionFor($current, $next),
                ...$next,
                'adjusted_by' => $actor->id,
                'adjusted_at' => now(),
            ]);

            $grade->unsetRelation('latestAdjustment');

            return ['adjustment' => $adjustment, 'changed' => true];
        });
    }

    /**
     * The state the submitted fields describe, with the override's percentage
     * derived from its score.
     *
     * Derived rather than accepted, because the percentage is what every average
     * in the gradebook is built from: an override that carried its own
     * percentage could report 8 of 10 as 80% and 40% in the same row.
     *
     * @param  array{dropped: bool, score: float|null, max_score: float|null, percentage: float|null, note: string|null}  $current
     * @param  array{dropped?: bool, score?: float|null, max_score?: float|null, note?: string|null}  $input
     * @return array{dropped: bool, score: float|null, max_score: float|null, percentage: float|null, note: string|null}
     */
    private function nextState(Grade $grade, array $current, array $input): array
    {
        $score = array_key_exists('score', $input)
            ? ($input['score'] === null ? null : (float) $input['score'])
            : $current['score'];

        // An override with no max of its own keeps the one it already had, and a
        // first override keeps the graded max: the denominator an instructor did
        // not change must not silently become 1.
        $maxScore = array_key_exists('max_score', $input)
            ? ($input['max_score'] === null ? null : (float) $input['max_score'])
            : ($current['max_score'] ?? (float) $grade->max_score);

        // Clearing the score clears the whole override, rather than leaving a
        // dangling maximum on a grade that has no score.
        if ($score === null) {
            $maxScore = null;
        }

        // An override is stored as a whole triple, so there is nothing sensible to
        // write without a maximum to divide by. The request rejects this first;
        // the guard keeps the invariant at the point the row is written.
        if ($score !== null && (! $maxScore || $maxScore <= 0)) {
            throw ValidationException::withMessages([
                'max_score' => ['A manual score needs a maximum score above zero.'],
            ]);
        }

        return [
            'dropped' => array_key_exists('dropped', $input) ? (bool) $input['dropped'] : $current['dropped'],
            'score' => $score,
            'max_score' => $maxScore,
            'percentage' => $score === null ? null : round(($score / $maxScore) * 100, 2),
            'note' => array_key_exists('note', $input) ? $input['note'] : $current['note'],
        ];
    }

    /**
     * @param  array{dropped: bool, score: float|null, max_score: float|null, percentage: float|null, note: string|null}  $current
     * @param  array{dropped: bool, score: float|null, max_score: float|null, percentage: float|null, note: string|null}  $next
     */
    private function isUnchanged(array $current, array $next): bool
    {
        return $current == $next;
    }

    /**
     * What the instructor did, for the trail. A drop and a restore outrank a
     * score change because dropping an overridden cell changes whether the score
     * counts at all, which is the more consequential of the two.
     *
     * @param  array{dropped: bool, score: float|null, max_score: float|null, percentage: float|null, note: string|null}  $current
     * @param  array{dropped: bool, score: float|null, max_score: float|null, percentage: float|null, note: string|null}  $next
     */
    private function actionFor(array $current, array $next): GradeAdjustmentAction
    {
        return match (true) {
            $next['dropped'] && ! $current['dropped'] => GradeAdjustmentAction::Drop,
            ! $next['dropped'] && $current['dropped'] => GradeAdjustmentAction::Restore,
            $next['percentage'] !== $current['percentage'] || $next['max_score'] !== $current['max_score'] => $next['percentage'] === null
                ? GradeAdjustmentAction::ClearOverride
                : GradeAdjustmentAction::Override,
            default => GradeAdjustmentAction::Annotate,
        };
    }
}
