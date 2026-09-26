<?php

namespace App\Http\Requests\Assignment;

use App\Support\UploadRules;
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

        // An assignment with no `allowed_file_types` used to apply no type
        // restriction at all, so any file was accepted. It now falls back to a
        // narrow default from config/lms.php, intersected with the globally
        // forbidden extension list.
        $itemRules = UploadRules::fileRules($types, 'default_assignment_extensions', $maxKb);

        return [
            'content' => ['nullable', 'string', 'max:20000'],
            'files' => ['nullable', 'array', 'max:5'],
            'files.*' => $itemRules,
        ];
    }
}
