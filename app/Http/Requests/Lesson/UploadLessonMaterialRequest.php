<?php

namespace App\Http\Requests\Lesson;

use Illuminate\Foundation\Http\FormRequest;

class UploadLessonMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->isInstructor();
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:51200'],
            'is_downloadable' => ['nullable', 'boolean'],
        ];
    }
}
