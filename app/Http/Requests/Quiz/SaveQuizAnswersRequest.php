<?php

namespace App\Http\Requests\Quiz;

use Illuminate\Foundation\Http\FormRequest;

class SaveQuizAnswersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            // The client sends every answer it holds, not just the ones that
            // changed: one row per touched question is idempotent, so a retried
            // or duplicated save is harmless and there is nothing to merge.
            'answers' => ['present', 'array'],
            'answers.*.question_id' => ['required', 'integer'],
            // `present` rather than `nullable` so a cleared question can be sent.
            // Being able to un-answer is what lets a student go back, and a
            // validation error here would trap the answer they just erased.
            'answers.*.answer' => ['present'],
        ];
    }
}
