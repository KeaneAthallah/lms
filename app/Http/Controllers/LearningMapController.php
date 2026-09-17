<?php

namespace App\Http\Controllers;

use App\Http\Resources\LearningMapResource;
use App\Services\LearningMapService;
use Illuminate\Http\Request;

class LearningMapController extends Controller
{
    public function show(Request $request)
    {
        $map = (new LearningMapService)->mapFor($request->user());

        return LearningMapResource::make($map);
    }
}
