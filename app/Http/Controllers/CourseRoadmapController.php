<?php

namespace App\Http\Controllers;

use App\Http\Resources\CourseRoadmapResource;
use App\Models\Course;
use App\Services\CourseRoadmapService;
use Illuminate\Http\Request;

class CourseRoadmapController extends Controller
{
    public function show(Request $request, Course $course)
    {
        $this->authorize('view', $course);

        $roadmap = (new CourseRoadmapService)->roadmapFor($request->user(), $course);

        return CourseRoadmapResource::make($roadmap);
    }
}
