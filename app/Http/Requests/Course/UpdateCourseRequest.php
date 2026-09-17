<?php

namespace App\Http\Requests\Course;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->isInstructor();
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'short_description' => ['required', 'string', 'max:500'],
            'description' => ['required', 'string'],
            'category_id' => ['nullable', 'integer', 'exists:course_categories,id'],
            'level' => ['required', 'in:beginner,intermediate,advanced'],
            'language' => ['required', 'string', 'max:20'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'learning_objectives' => ['nullable', 'array'],
            'learning_objectives.*' => ['string', 'max:500'],
            'requirements' => ['nullable', 'array'],
            'requirements.*' => ['string', 'max:500'],
            'status' => ['nullable', 'in:draft,published,archived'],
            'thumbnail' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}
