<?php

namespace App\Http\Controllers;

use App\Http\Resources\GradeResource;
use App\Models\Grade;
use Illuminate\Http\Request;

class GradeController extends Controller
{
    public function index(Request $request)
    {
        $grades = Grade::where('student_id', $request->user()->id)
            ->with('course:id,title,slug')
            ->orderByDesc('graded_at')
            ->paginate(15);

        return GradeResource::collection($grades);
    }
}
