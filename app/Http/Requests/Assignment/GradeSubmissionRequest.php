<?php

namespace App\Http\Requests\Assignment;

use App\Models\AssignmentSubmission;
use Illuminate\Foundation\Http\FormRequest;

class GradeSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $submission = $this->route('submission');

        $maxScore = is_object($submission)
            ? $submission->assignment?->max_score
            : AssignmentSubmission::whereKey($submission)->first()?->assignment?->max_score;

        return [
            'grade' => ['required', 'numeric', 'min:0', 'max:'.(float) ($maxScore ?? 100)],
            'feedback' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
