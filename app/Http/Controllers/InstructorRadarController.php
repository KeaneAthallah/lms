<?php

namespace App\Http\Controllers;

use App\Http\Resources\InstructorRadarResource;
use App\Models\Course;
use App\Services\InstructorRadarService;
use Illuminate\Http\Request;

class InstructorRadarController extends Controller
{
    public function show(Request $request, Course $course)
    {
        $this->authorize('manage', $course);

        $radar = (new InstructorRadarService)->radarFor($course);

        return InstructorRadarResource::make($radar);
    }
}
