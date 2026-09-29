<?php

namespace App\Http\Requests\Gradebook;

use App\Http\Requests\Concerns\AuthorizesContentAuthors;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGradebookCategoryRequest extends FormRequest
{
    use AuthorizesContentAuthors;

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('gradebook_categories', 'name')->where('course_id', $this->route('course')->id)],
            'weight' => ['required', 'numeric', 'between:0,100'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
