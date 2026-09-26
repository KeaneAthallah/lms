<?php

namespace App\Http\Requests\Quiz;

use App\Http\Requests\Concerns\AuthorizesContentAuthors;
use App\Http\Requests\Concerns\QuizFieldRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreQuizRequest extends FormRequest
{
    use AuthorizesContentAuthors, QuizFieldRules;
}
