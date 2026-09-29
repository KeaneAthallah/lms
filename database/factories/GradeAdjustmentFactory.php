<?php

namespace Database\Factories;

use App\GradeAdjustmentAction;
use App\Models\Grade;
use App\Models\GradeAdjustment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GradeAdjustment>
 */
class GradeAdjustmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'grade_id' => Grade::factory(),
            'action' => GradeAdjustmentAction::Override,
            'dropped' => false,
            'score' => 85,
            'max_score' => 100,
            'percentage' => 85,
            'adjusted_by' => User::factory()->instructor(),
            'adjusted_at' => now(),
        ];
    }

    /**
     * `student_id` and `course_id` are copies of the grade's own, so the trail is
     * one query rather than a join. They are filled in from the grade rather than
     * guessed, which also means a factory-built adjustment can never disagree
     * with the row it describes.
     */
    protected function configure(): static
    {
        return $this->afterMaking(function (GradeAdjustment $adjustment): void {
            $grade = $adjustment->grade_id !== null
                ? Grade::find($adjustment->grade_id)
                : $adjustment->grade;

            $adjustment->student_id = $grade?->student_id;
            $adjustment->course_id = $grade?->course_id;
        });
    }

    public function dropped(): static
    {
        return $this->state(fn (): array => [
            'action' => GradeAdjustmentAction::Drop,
            'dropped' => true,
            'score' => null,
            'max_score' => null,
            'percentage' => null,
        ]);
    }

    public function note(string $note): static
    {
        return $this->state(fn (): array => ['note' => $note]);
    }
}
