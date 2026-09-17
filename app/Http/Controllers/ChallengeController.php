<?php

namespace App\Http\Controllers;

use App\Services\ChallengeService;
use Illuminate\Http\Request;

class ChallengeController extends Controller
{
    public function show(Request $request)
    {
        $challenge = (new ChallengeService)->build($request->user());

        return response()->json(['data' => $challenge]);
    }

    public function grade(Request $request)
    {
        $validated = $request->validate([
            'questions' => ['required', 'array', 'max:5'],
            'answers' => ['required', 'array'],
        ]);

        $questionIds = array_map('intval', $validated['questions']);
        $answers = collect($validated['answers'])
            ->mapWithKeys(fn (array $row) => [(int) $row['question_id'] => $row['answer']])
            ->all();

        $result = (new ChallengeService)->grade($questionIds, $answers);

        return response()->json(['data' => $result]);
    }
}
