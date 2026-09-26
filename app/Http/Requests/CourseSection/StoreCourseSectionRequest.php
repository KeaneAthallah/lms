<?php

namespace App\Http\Requests\CourseSection;

use App\Http\Requests\Concerns\AuthorizesContentAuthors;
use Illuminate\Foundation\Http\FormRequest;

class StoreCourseSectionRequest extends FormRequest
{
    use AuthorizesContentAuthors;

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
