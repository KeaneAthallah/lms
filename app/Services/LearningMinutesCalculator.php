<?php

namespace App\Services;

use App\Models\LessonProgress;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The single definition of what counts as a learning minute.
 *
 * Two endpoints report this number under different names -- the insights
 * summary's `learning_minutes_7d` and the portfolio's `learning_minutes_total`
 * -- and they used to carry their own copies of the arithmetic. The copies were
 * identical down to the cap and the null guards, which is exactly what makes
 * duplicated definitions dangerous: nothing looks wrong until one of them is
 * edited, and then the two endpoints disagree about the same student's time
 * with no error anywhere. The window is the only thing that differs, so it is
 * the only thing that is a parameter here.
 *
 * The rules, all of them deliberate:
 *
 *  - A completed lesson contributes its recorded `duration_seconds`, rounded
 *    *up* to the next whole minute. A 90-second lesson is a minute of study,
 *    not zero, so truncating would quietly under-report every short lesson.
 *  - A lesson with no recorded duration contributes nothing. The platform never
 *    invents a duration it was not told.
 *  - A submitted quiz attempt contributes its elapsed time, also rounded up,
 *    because the student spent that time regardless of how they scored.
 *  - Each attempt is capped at two hours. The cap is per attempt and is applied
 *    before summing, so two long attempts are worth more than one while no
 *    single forgotten tab can report an all-nighter. Capping the total instead
 *    would make two long attempts indistinguishable from one.
 *  - An attempt that ends before it starts contributes zero rather than a
 *    negative number, so clock skew cannot quietly under-report a total.
 *  - An unsubmitted attempt contributes nothing: with no end there is no
 *    elapsed time to count.
 *
 * Note that `LearningInsightService::estimatedMinutes()`, which decides what a
 * single lesson card *displays*, rounds durations rather than ceiling them. That
 * disagreement is known and still open, and it lives in the display layer on
 * purpose: the card answers "how long is this lesson" while this answers "how
 * long did this student spend". See the audit's residual-risk note before
 * "unifying" the two.
 */
class LearningMinutesCalculator
{
    public function minutes(User $student, ?Carbon $since = null): int
    {
        $completed = LessonProgress::query()
            ->where('student_id', $student->id)
            ->whereNotNull('completed_at')
            ->when($since, fn (Builder $query): Builder => $query->where('completed_at', '>=', $since))
            ->with('lesson:id,duration_seconds')
            ->get(['lesson_id', 'completed_at']);

        $lessonMinutes = $completed->sum(
            fn (LessonProgress $row) => is_numeric($row->lesson?->duration_seconds) && (int) $row->lesson->duration_seconds > 0
                ? (int) ceil((int) $row->lesson->duration_seconds / 60)
                : 0
        );

        $attempts = QuizAttempt::query()
            ->where('student_id', $student->id)
            ->whereNotNull('submitted_at')
            ->whereNotNull('started_at')
            ->when($since, fn (Builder $query): Builder => $query->where('submitted_at', '>=', $since))
            ->get(['started_at', 'submitted_at']);

        $attemptMinutes = $attempts->sum(function (QuizAttempt $attempt): int {
            $seconds = max(0, (int) $attempt->started_at->diffInSeconds($attempt->submitted_at));

            return min(120, (int) ceil($seconds / 60));
        });

        return $lessonMinutes + $attemptMinutes;
    }
}
