<?php

namespace App\Http\Requests\Gradebook;

use App\Http\Requests\Concerns\AuthorizesContentAuthors;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * An instructor's decision about one grade: a manual score, exclusion from the
 * average, a note, or any combination.
 *
 * Every key is optional and means "set it to this", so a dialog can send only
 * what it touched and an untouched save is a no-op rather than a wipe.
 */
class AdjustGradeRequest extends FormRequest
{
    use AuthorizesContentAuthors;

    public function rules(): array
    {
        return [
            'dropped' => ['sometimes', 'boolean'],
            'score' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'max_score' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * The percentage is derived from the score and the maximum, so those two
     * have to agree -- and the maximum a submitted score is measured against may
     * be the one already on the grade rather than one in the payload.
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $score = $this->input('score');

            if ($score === null) {
                return;
            }

            $max = $this->effectiveMaxScore();

            if ($max === null) {
                $validator->errors()->add('max_score', 'A manual score needs a maximum score above zero.');

                return;
            }

            if ((float) $score > $max) {
                $validator->errors()->add('score', 'The score cannot be higher than the maximum score.');
            }
        }];
    }

    /**
     * The maximum the submitted score would be reported against: the submitted
     * one, else the override already in force, else the graded maximum.
     */
    private function effectiveMaxScore(): ?float
    {
        if ($this->filled('max_score')) {
            return (float) $this->input('max_score');
        }

        $grade = $this->route('grade');
        $state = $grade->adjustmentState();

        return $state['max_score'] ?? (float) $grade->max_score;
    }
}
