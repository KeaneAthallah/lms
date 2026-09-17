<?php

namespace App\Http\Requests\Assignment;

use Illuminate\Foundation\Http\FormRequest;

class SubmitAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $assignment = $this->route('assignment');
        $maxKb = max(1, (int) ($assignment?->max_file_size_kb ?? 10240));
        $types = array_values(array_filter((array) ($assignment?->allowed_file_types ?? [])));

        $fileRules = ['nullable', 'array', 'max:5'];

        $itemRules = ['file', 'max:'.$maxKb];
        if ($types !== []) {
            $itemRules[] = 'mimes:'.implode(',', $types);
        }

        return [
            'content' => ['nullable', 'string', 'max:20000'],
            'files' => $fileRules,
            'files.*' => $itemRules,
        ];
    }
}
