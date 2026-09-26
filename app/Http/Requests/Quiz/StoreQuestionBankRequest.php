<?php

namespace App\Http\Requests\Quiz;

use App\Http\Requests\Concerns\AuthorizesContentAuthors;
use Illuminate\Foundation\Http\FormRequest;

class StoreQuestionBankRequest extends FormRequest
{
    use AuthorizesContentAuthors;

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
