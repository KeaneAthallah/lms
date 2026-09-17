<?php

namespace App\Http\Requests\Assignment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->isInstructor();
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'instructions' => ['nullable', 'string'],
            'due_at' => ['nullable', 'date'],
            'max_score' => ['required', 'numeric', 'min:1'],
            'allowed_file_types' => ['nullable', 'array'],
            'allowed_file_types.*' => ['string', 'in:pdf,doc,docx,txt,ppt,pptx,xls,xlsx,png,jpg,jpeg,zip,csv'],
            'max_file_size_kb' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ];
    }
}
