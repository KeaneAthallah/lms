<?php

namespace App\Http\Controllers;

use App\Http\Resources\LearningInsightsResource;
use App\Services\LearningInsightService;
use Illuminate\Http\Request;

class LearningInsightsController extends Controller
{
    public function __construct(protected LearningInsightService $insights) {}

    public function show(Request $request): LearningInsightsResource
    {
        $student = $request->user();

        return new LearningInsightsResource($this->insights->insightsFor($student));
    }
}
