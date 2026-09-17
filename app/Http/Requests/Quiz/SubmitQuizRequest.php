<?php

namespace App\Http\Requests\Quiz;

use Illuminate\Foundation\Http\FormRequest;

class SubmitQuizRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'questions' => ['required', 'array'],
            'questions.*.question_id' => ['required', 'integer'],
            'questions.*.answer' => ['nullable'],
        ];
    }
}
