<?php

namespace App\Http\Requests\Lesson;

use App\Http\Requests\Concerns\AuthorizesContentAuthors;
use App\Support\UploadRules;
use Illuminate\Foundation\Http\FormRequest;

class UploadLessonMaterialRequest extends FormRequest
{
    use AuthorizesContentAuthors;

    private const MAX_KB = 102400;

    public function rules(): array
    {
        return [
            // Materials are instructor-authored but served to every enrolled
            // student, so uploads are checked against an allowlist rather than a
            // size limit alone. Without this, `.php`, `.html` and `.svg` were
            // all accepted.
            'file' => UploadRules::fileRules([], 'material_extensions', self::MAX_KB),
            'is_downloadable' => ['nullable', 'boolean'],
        ];
    }
}
