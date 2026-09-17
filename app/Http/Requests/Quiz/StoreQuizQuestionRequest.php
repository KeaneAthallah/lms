<?php

namespace App\Http\Requests\Quiz;

use App\QuizQuestionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuizQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->isInstructor();
    }

    public function rules(): array
    {
        $types = array_column(QuizQuestionType::cases(), 'value');

        return [
            'type' => ['required', Rule::in($types)],
            'question_text' => ['required', 'string', 'max:2000'],
            'points' => ['required', 'numeric', 'min:0.1'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'options' => ['array'],
            'options.*.option_text' => ['required', 'string', 'max:1000'],
            'options.*.is_correct' => ['nullable', 'boolean'],
            'options.*.explanation' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
