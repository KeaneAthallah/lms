<?php

namespace App\Http\Requests\Lesson;

use App\LessonType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->isInstructor();
    }

    public function rules(): array
    {
        $types = array_column(LessonType::cases(), 'value');

        return [
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in($types)],
            'content' => ['nullable', 'string'],
            'video_url' => ['nullable', 'url', 'max:500'],
            'video' => ['nullable', 'file', 'mimes:mp4,webm,mov,m4v,ogg', 'max:204800'],
            'remove_video' => ['nullable', 'boolean'],
            'external_url' => ['nullable', 'url', 'max:500'],
            'duration_seconds' => ['nullable', 'integer', 'min:1'],
            'is_published' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
