<?php

namespace App\Http\Requests\Quiz;

use Illuminate\Foundation\Http\FormRequest;

class SubmitQuizRequest extends FormRequest
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
            // `present` rather than `required`, so a submit that carries no
            // answers is legal. Autosave is the source of truth, and a student
            // whose time ran out is submitted with whatever reached the server --
            // an empty payload there is a request to grade the saved drafts, not
            // a malformed request. `required` would reject the one submit that
            // must never be lost.
            'questions' => ['present', 'array'],
            'questions.*.question_id' => ['required', 'integer'],
            'questions.*.answer' => ['present'],
        ];
    }
}
